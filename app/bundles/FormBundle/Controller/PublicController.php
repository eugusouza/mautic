<?php
/**
 * @package     Mautic
 * @copyright   2014 Mautic, NP. All rights reserved.
 * @author      Mautic
 * @link        http://mautic.com
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\FormBundle\Controller;

use Mautic\CoreBundle\Controller\FormController as CommonFormController;
use Mautic\CoreBundle\Helper\InputHelper;
use Symfony\Component\HttpFoundation\Response;


class PublicController extends CommonFormController
{
    public function submitAction()
    {
        $post    = $this->request->request->get('mauticform');
        $server  = $this->request->server->all();
        $return  = $post['return'];
        if (empty($return)) {
            //try to get it from the HTTP_REFERER
            $return = $server['HTTP_REFERER'];
        }
        //remove mauticError and mauticMessage from the referer so it doesn't get sent back
        $return = InputHelper::url($return, null, null, array('mauticError', 'mauticMessage'));
        $query  = (strpos($return, '?') === false) ? '?' : '&';

        $translator = $this->get('translator');

        //check to ensure there is a formid
        if (!isset($post['formid'])) {
            $error =  $translator->trans('mautic.form.submit.error.unavailable', array(), 'flashes');
        } else {
            $formModel = $this->factory->getModel('form.form');
            $form      = $formModel->getEntity($post['formid']);

            //check to see that the form was found
            if ($form === null) {
                $error = $translator->trans('mautic.form.submit.error.unavailable', array(), 'flashes');
            } else {
                //get what to do immediately after successful post
                $postAction         = $form->getPostAction();
                $postActionProperty = $form->getPostActionProperty();

               //check to ensure the form is published
                $status = $form->getPublishStatus();
                $dateTemplateHelper = $this->get('mautic.core.template.helper.date');
                if ($status == 'pending') {
                    $error = $translator->trans('mautic.form.submit.error.pending', array(
                        '%date%' => $dateTemplateHelper->toFull($form->getPublishUp())
                    ), 'flashes');
                } elseif ($status == 'expired') {
                    $error = $translator->trans('mautic.form.submit.error.expired', array(
                        '%date%' => $dateTemplateHelper->toFull($form->getPublishDown())
                    ), 'flashes');
                } elseif ($status != 'published') {
                    $error = $translator->trans('mautic.form.submit.error.unavailable', array(), 'flashes');
                } else {
                    $result = $this->factory->getModel('form.submission')->saveSubmission($post, $server, $form);
                    if (!empty($result['errors'])) {
                        $error = ($result['errors']) ?
                            $this->get('translator')->trans('mautic.form.submission.errors') . '<br /><ol><li>' .
                            implode("</li><li>", $result['errors']) . '</li></ol>' : false;
                    } elseif (!empty($result['callback'])) {
                        $callback = $result['callback']['callback'];
                        if (is_callable($callback)) {
                            if (is_array($callback)) {
                                $reflection = new \ReflectionMethod($callback[0], $callback[1]);
                            } elseif (strpos($callback, '::') !== false) {
                                $parts      = explode('::', $callback);
                                $reflection = new \ReflectionMethod($parts[0], $parts[1]);
                            } else {
                                new \ReflectionMethod(null, $callback);
                            }

                            //add the factory to the arguments
                            $result['callback']['factory'] = $this->factory;

                            $pass = array();
                            foreach ($reflection->getParameters() as $param) {
                                if (isset($result['callback'][$param->getName()])) {
                                    $pass[] = $result['callback'][$param->getName()];
                                } else {
                                    $pass[] = null;
                                }
                            }
                            return $reflection->invokeArgs($this, $pass);
                        }
                    }
                }
            }
        }

        if (!empty($error)) {
            if ($return) {
                return $this->redirect($return . $query . 'mauticError=' . rawurlencode($error));
            } else {
                $msg     = $error;
                $msgType = 'error';
            }
        } elseif ($postAction == 'redirect') {
            return $this->redirect($postActionProperty);
        } elseif ($postAction == 'return') {
            if (!empty($return)) {
                if (!empty($postActionProperty)) {
                    $return .= $query . 'mauticMessage=' . rawurlencode($postActionProperty);
                }
                return $this->redirect($return);
            } else {
                $msg = $this->get('translator')->trans('mautic.form.submission.thankyou');
            }
        } else {
            $msg = $postActionProperty;
        }

        return $this->render('MauticEmailBundle::message.html.php', array(
            'message'  => $msg,
            'type'     => (empty($msgType)) ? 'notice' : $msgType,
            'template' => $this->factory->getParameter('default_theme')
        ));
    }


    /**
     * Generates JS file for automatic form generation
     */
    public function generateAction ()
    {
        $formId = InputHelper::int($this->request->get('id'));
        $model  = $this->factory->getModel('form.form');
        $form   = $model->getEntity($formId);
        $js     = '';

        if ($form !== null) {
            $status = $form->getPublishStatus();
            if ($status == 'published') {
                $js = $form->getCachedJs();
            }
        }

        $response = new Response();
        $response->setContent($js);
        $response->setStatusCode(Response::HTTP_OK);
        $response->headers->set('Content-Type', 'text/javascript');
        return $response;
    }

}