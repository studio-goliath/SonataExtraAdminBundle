<?php

namespace Picoss\SonataExtraAdminBundle\Controller;

use Sonata\AdminBundle\Controller\CRUDController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class ExtraAdminController extends CRUDController
{
    /**
     * Move element
     *
     * @param integer $id
     * @param string $position
     */
    public function moveAction($id, $childId = null, $position)
    {

        $objectId = $childId !== null ? $childId : $id;

        $object = $this->admin->getObject($objectId);

        $sortableHandler = $this->get('picoss.sonataextraadmin.handler.sortable');
        $lastPosition = $sortableHandler->getLastPosition($object);
        $position = $sortableHandler->getPosition($object, $position, $lastPosition);

        $object->setPosition($position);
        $this->admin->update($object);

        if ($this->isXmlHttpRequest()) {
            return $this->renderJson(array(
                'result' => 'ok',
                'objectId' => $this->admin->getNormalizedIdentifier($object)
            ));
        }
        $this->addFlash('sonata_flash_info', $this->get('translator')->trans('flash_position_updated_successfully', array(), 'PicossSonataExtraAdminBundle'));

        return new RedirectResponse($this->admin->generateUrl('list', $this->admin->getFilterParameters()));
    }

    public function historyRevertAction($id, $revision)
    {
        $id     = $this->get('request')->get($this->admin->getIdParameter());
        $object = $this->admin->getObject($id);

        if (!$object) {
            throw new NotFoundHttpException(sprintf('unable to find the object with id : %s', $id));
        }

        $request = $this->get('request');
        if ($request->getMethod() == 'POST') {
            // check the csrf token
            $this->validateCsrfToken('sonata.history.revert');

            try {
                $manager = $this->get('sonata.admin.audit.manager');

                if (!$manager->hasReader($this->admin->getClass())) {
                    throw new NotFoundHttpException(sprintf('unable to find the audit reader for class : %s', $this->admin->getClass()));
                }

                $reader = $manager->getReader($this->admin->getClass());
                $reader->revert($object, $revision);

                if ($this->isXmlHttpRequest()) {
                    return $this->renderJson(array('result' => 'ok'));
                }

                $this->addFlash('sonata_flash_info', $this->get('translator')->trans('flash_history_revert_successfull', array(), 'PicossSonataExtraAdminBundle'));

            } catch (ModelManagerException $e) {

                if ($this->isXmlHttpRequest()) {
                    return $this->renderJson(array('result' => 'error'));
                }

                $this->addFlash('sonata_flash_info', $this->get('translator')->trans('flash_history_revert_error', array(), 'PicossSonataExtraAdminBundle'));
            }

            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        return $this->render($this->admin->getTemplate('history_revert'), array(
            'object'     => $object,
            'revision'   => $revision,
            'action'     => 'revert',
            'csrf_token' => $this->getCsrfToken('sonata.history.revert')
        ));
    }

    /**
     * return the Response object associated to the trash action
     *
     * @throws \Symfony\Component\Security\Core\Exception\AccessDeniedException
     *
     * @return Response
     */
    public function trashAction()
    {
        if (false === $this->admin->isGranted('LIST')) {
            throw new AccessDeniedException();
        }

        $em = $this->getDoctrine()->getManager();
        $em->getFilters()->disable('softdeleteable');
        $em->getFilters()->enable('softdeleteabletrash');

        $datagrid = $this->admin->getDatagrid();
        $formView = $datagrid->getForm()->createView();

        // set the theme for the current Admin Form
        $this->get('twig')->getExtension('form')->renderer->setTheme($formView, $this->admin->getFilterTheme());

        return $this->render($this->admin->getTemplate('trash'), array(
            'action'     => 'trash',
            'form'       => $formView,
            'datagrid'   => $datagrid,
            'csrf_token' => $this->getCsrfToken('sonata.batch'),
        ));
    }

    public function untrashAction($id)
    {
        $em = $this->getDoctrine()->getManager();
        $em->getFilters()->disable('softdeleteable');
        // No need here to filter on deleted items only
        //$em->getFilters()->enable('softdeleteabletrash');

        $id     = $this->get('request')->get($this->admin->getIdParameter());
        $object = $this->admin->getObject($id);

        if (!$object) {
            throw new NotFoundHttpException(sprintf('unable to find the object with id : %s', $id));
        }

        $restrictions = $this->getUntrashRestrictions($em, $object);

        if (count($restrictions)>0) {
            $this->addFlash('sonata_flash_error', $this->get('translator')->trans('flash_untrash_restriction_error', array(), 'PicossSonataExtraAdminBundle'));
            return new RedirectResponse($this->admin->generateUrl('trash'));
        }

        $request = $this->get('request');
        if ($request->getMethod() == 'POST') {
            // check the csrf token
            $this->validateCsrfToken('sonata.untrash');

            try {
                $object->setDeletedAt(null);
                $this->admin->update($object);

                if ($this->isXmlHttpRequest()) {
                    return $this->renderJson(array('result' => 'ok'));
                }

                $this->addFlash('sonata_flash_info', $this->get('translator')->trans('flash_untrash_successfull', array(), 'PicossSonataExtraAdminBundle'));

            } catch (ModelManagerException $e) {

                if ($this->isXmlHttpRequest()) {
                    return $this->renderJson(array('result' => 'error'));
                }

                $this->addFlash('sonata_flash_info', $this->get('translator')->trans('flash_untrash_error', array(), 'PicossSonataExtraAdminBundle'));
            }

            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        return $this->render($this->admin->getTemplate('untrash'), array(
            'object'     => $object,
            'action'     => 'untrash',
            'csrf_token' => $this->getCsrfToken('sonata.untrash')
        ));
    }

    // Restrict untrash if owned entities are trashed (if products are in collections, that collections are trashed, products have to be trashed as well)
    protected function getUntrashRestrictions($em, $object)
    {
        $restrictions = array();

        $evm = $em->getEventManager();

        $className = get_class($object);

        $cmf = $em->getMetadataFactory();
        $metadata = $cmf->getMetadataFor($className);

        // Récupère le listener SoftDeleteable
        $softDeleteListener = false;
        foreach ($evm->getListeners() as $listeners) {
            foreach ($listeners as $listener) {
                if ($listener instanceof \Gedmo\SoftDeleteable\SoftDeleteableListener) {
                    $softDeleteListener = $listener;

                    break 2;
                }
            }
        }

        if ($softDeleteListener) {

            foreach ($metadata->associationMappings as $key => $associationMapping) {
                if ($associationMapping['isOwningSide'] && $associationMapping['isCascadeRemove']) {
                    $targetEntity = $associationMapping['targetEntity'];
                    $config = $softDeleteListener->getConfiguration($em, $targetEntity);

                    if (isset($config['softDeleteable']) && $config['softDeleteable']) {
                        $restrictions[] = $targetEntity;
                        /*
                        $this->addFlash('sonata_flash_info', $this->get('translator')->trans('flash_untrash_error', array(), 'PicossSonataExtraAdminBundle'));

                        if ($this->isXmlHttpRequest()) {
                            return $this->renderJson(array('result' => 'error'));
                        } else {
                            return new RedirectResponse($this->admin->generateUrl('trash'));
                        }
                         */
                    }
                }
            }
        }

        return $restrictions;
    }
}
