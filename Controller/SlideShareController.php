<?php
/*
 * This file is part of the CampaignChain package.
 *
 * (c) CampaignChain Inc. <info@campaignchain.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CampaignChain\Activity\SlideShareBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use CampaignChain\Location\SlideShareBundle\Entity\SlideShareUser;
use CampaignChain\Operation\SlideShareBundle\Form\Type\SlideShareOperationType;
use CampaignChain\CoreBundle\Entity\Operation;
use CampaignChain\CoreBundle\Entity\Location;
use CampaignChain\CoreBundle\Entity\Medium;
use CampaignChain\Operation\SlideShareBundle\Entity\Slideshow;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\GetSetMethodNormalizer;

class SlideShareController extends Controller
{

    const ACTIVITY_BUNDLE_NAME          = 'campaignchain/activity-slideshare';
    const ACTIVITY_MODULE_IDENTIFIER    = 'campaignchain-slideshare';
    const OPERATION_BUNDLE_NAME         = 'campaignchain/operation-slideshare';
    const OPERATION_MODULE_IDENTIFIER   = 'campaignchain-slideshare';
    const LOCATION_BUNDLE_NAME          = 'campaignchain/location-slideshare';
    const LOCATION_MODULE_IDENTIFIER    = 'campaignchain-slideshare-user';
    const TRIGGER_HOOK_IDENTIFIER       = 'campaignchain-due';

    public function newAction(Request $request)
    {
        $wizard = $this->get('campaignchain.core.activity.wizard');
        $campaign = $wizard->getCampaign();
        $activity = $wizard->getActivity();
        $location = $wizard->getLocation();

        $activity->setEqualsOperation(true);

        $locationService = $this->get('campaignchain.core.location');
        $location = $locationService->getLocation($location->getId());        
        
        // retrieve slide decks
        $client = $this->get('campaignchain.channel.slideshare.rest.client');
        $connection = $client->connectByLocation($location);
        $xml = $connection->getUserSlideshows();
        $privateSlideshowCount = 0;
        $slideshows = array();
        foreach ($xml->Slideshow as $slideshow) {
            if ($slideshow->PrivacyLevel == 1) {
                $privateSlideshowCount++;
                $id = (int)$slideshow->ID;
                $title = (string)$slideshow->Title;
                $url = (string)$slideshow->URL;
                $slideshows[$id] = array('title' => $title, 'url' => $url); 
            }
        }
        
        if ($privateSlideshowCount == 0) {
            $this->get('session')->getFlashBag()->add(
                'warning',
                'No private slideshows found.'
            );

            return $this->redirect(
                $this->generateUrl('campaignchain_core_activities_new')
            );
        } 

        // check that available private slideshows are not already in use
        $availableSlideshows = array();
        if ($privateSlideshowCount > 0) {
        
            foreach($slideshows as $key => $value) {
                if (!$locationService->existsInAllCampaigns(
                  self::LOCATION_BUNDLE_NAME, self::LOCATION_MODULE_IDENTIFIER, $key
                )) {
                    $availableSlideshows[$key] = $value;
                }
            }
            
            if (!count($availableSlideshows)) {
                $this->get('session')->getFlashBag()->add(
                    'warning',
                    'All available private slideshows have already been added to campaigns.'
                );

                return $this->redirect(
                    $this->generateUrl('campaignchain_core_activities_new')
                );         
            }
        }

        $activityType = $this->get('campaignchain.core.form.type.activity');
        $activityType->setBundleName(self::ACTIVITY_BUNDLE_NAME);
        $activityType->setModuleIdentifier(self::ACTIVITY_MODULE_IDENTIFIER);
        $activityType->showNameField(false);

        $operationType = new SlideShareOperationType($this->getDoctrine()->getManager(), $this->get('service_container'));

        $location = $locationService->getLocation($location->getId());

        $operationType->setLocation($location);

        foreach ($availableSlideshows as $key => $value) {
            $formDataSlideshowsArr[$key] = $value['title'];
        }
        $operationType->setSlideshows($formDataSlideshowsArr);

        $operationForms[] = array(
            'identifier' => self::OPERATION_MODULE_IDENTIFIER,
            'form' => $operationType,
            'label' => 'Activate Slideshow',
        );
        $activityType->setOperationForms($operationForms);
        $activityType->setCampaign($campaign);
        
        $form = $this->createForm($activityType, $activity);
             
        $form->handleRequest($request);
        
        if ($form->isValid()) {
            $sid = $form->get(self::OPERATION_MODULE_IDENTIFIER)->getData()['slideshow'];
            $activity = $wizard->end();
            $title = $availableSlideshows[$sid]['title'];
            $activity->setName($title);

            // Get the operation module.
            $operationService = $this->get('campaignchain.core.operation');
            $operationModule = $operationService->getOperationModule(self::OPERATION_BUNDLE_NAME, self::OPERATION_MODULE_IDENTIFIER);

            // The activity equals the operation. Thus, we create a new operation with the same data.
            $operation = new Operation();
            $operation->setName($title);
            $operation->setActivity($activity);
            $activity->addOperation($operation);
            $operationModule->addOperation($operation);
            $operation->setOperationModule($operationModule);

            // The Operation creates a Location, i.e. the slideshow 
            // will be accessible through a URL after publishing.
            // Get the location module for the user stream.
            $locationService = $this->get('campaignchain.core.location');
            $locationModule = $locationService->getLocationModule(
                self::LOCATION_BUNDLE_NAME,
                self::LOCATION_MODULE_IDENTIFIER
            );
            
            $location = new Location();
            $location->setLocationModule($locationModule);
            $location->setParent($activity->getLocation());
            $location->setIdentifier($sid);
            $location->setName($availableSlideshows[$sid]['title']);
            $location->setUrl($availableSlideshows[$sid]['url']);
            $location->setStatus(Medium::STATUS_UNPUBLISHED);
            $location->setOperation($operation);
            $operation->addLocation($location);
            
            $slideshowOperation = new Slideshow();
            $slideshowOperation->setOperation($operation);
            $slideshowOperation->setUrl($availableSlideshows[$sid]['url']);
            $slideshowOperation->setIdentifier($sid);
            $slideshowOperation->setTitle($availableSlideshows[$sid]['title']);
            
            $repository = $this->getDoctrine()->getManager();

            // Make sure that data stays intact by using transactions.
            try {
                $repository->getConnection()->beginTransaction();

                $repository->persist($operation);
                $repository->persist($activity);
                $repository->persist($slideshowOperation);

                $repository->flush();

                $hookService = $this->get('campaignchain.core.hook');
                $activity = $hookService->processHooks(self::ACTIVITY_BUNDLE_NAME, self::ACTIVITY_MODULE_IDENTIFIER, $activity, $form, true);
                $repository->flush();

                /*
                 * Edit the slideshow so that embeds are allowed and we can display
                 * the slideshow from within CampaignChain.
                 */
                $connection->allowEmbedsUserSlideshow($sid);

                $repository->getConnection()->commit();
            } catch (\Exception $e) {
                $repository->getConnection()->rollback();
                throw $e;
            }

            $this->get('session')->getFlashBag()->add(
                'success',
                'The slideshow <a href="'.$this->generateUrl('campaignchain_core_activity_edit', array('id' => $activity->getId())).'">'.$activity->getName().'</a> has been added successfully.'
            );

            // Status Update to be sent immediately?
            // TODO: This is an intermediary hardcoded hack and should be instead handled by the scheduler.
            if ($form->get('campaignchain_hook_campaignchain_due')->has('execution_choice') && $form->get('campaignchain_hook_campaignchain_due')->get('execution_choice')->getData() == 'now') {
                $job = $this->get('campaignchain.job.operation.slideshare.publish_slideshow');
                $job->execute($operation->getId());
                // TODO: Add different flashbag which includes link to published slideshow
            }

            return $this->redirect($this->generateUrl('campaignchain_core_activities'));
            
        }

        $campaignService = $this->get('campaignchain.core.campaign');
        $campaign = $campaignService->getCampaign($campaign);

        return $this->render(
            'CampaignChainCoreBundle:Operation:new.html.twig',
            array(
                'page_title' => 'Schedule Slideshow',
                'activity' => $activity,
                'campaign' => $campaign,
                'campaign_module' => $campaign->getCampaignModule(),
                'channel_module' => $wizard->getChannelModule(),
                'channel_module_bundle' => $wizard->getChannelModuleBundle(),
                'location' => $location,
                'form' => $form->createView(),
                'form_submit_label' => 'Save',
                'form_cancel_route' => 'campaignchain_core_activities_new'
            ));

    }

    public function editAction(Request $request, $id)
    {
        $activityService = $this->get('campaignchain.core.activity');
        $activity = $activityService->getActivity($id);
        $campaign = $activity->getCampaign();

        // Get the one operation.
        $operation = $activityService->getOperation($id);

        $slideshowOperation = $this->getDoctrine()
            ->getRepository('CampaignChainOperationSlideShareBundle:Slideshow')
            ->findOneByOperation($operation);

        if (!$slideshowOperation) {
            throw new \Exception(
                'No slideshow found for Operation with ID '.$operation->getId()
            );
        }

        $client = $this->get('campaignchain.channel.slideshare.rest.client');
        $connection = $client->connectByActivity($activity);
        $xml = $connection->getSlideshowById($slideshowOperation->getIdentifier());

        /*
         * If the slideshow embed option was revoked on SlideShare.com, then
         * configure it to work again, so that we can display the slideshow
         * within CampaignChain.
         */
        if($xml->AllowEmbed == 0) {
            $returnXML = $connection->allowEmbedsUserSlideshow($xml->ID);
            print_r($returnXML);
        }

        $activityType = $this->get('campaignchain.core.form.type.activity');
        $activityType->setBundleName(self::ACTIVITY_BUNDLE_NAME);
        $activityType->setModuleIdentifier(self::ACTIVITY_MODULE_IDENTIFIER);
        $activityType->showNameField(false);
        $activityType->setCampaign($campaign);

        $form = $this->createForm($activityType, $activity);

        $form->handleRequest($request);

        if ($form->isValid()) {
            
            $repository = $this->getDoctrine()->getManager();

            // Make sure that data stays intact by using transactions.
            try {
                $activityService = $this->get('campaignchain.core.activity');
                $operation = $activityService->getOperation($id);
                $operation->setName($activity->getName());
                $repository->persist($operation);
                $repository->persist($slideshowOperation);

                $hookService = $this->get('campaignchain.core.hook');
                $activity = $hookService->processHooks(self::ACTIVITY_BUNDLE_NAME, self::ACTIVITY_MODULE_IDENTIFIER, $activity, $form, true);
                $repository->persist($activity);

                $repository->flush();

            } catch (\Exception $e) {
                $repository->getConnection()->rollback();
                throw $e;
            }

            // TODO: delete, for testing only
            //$job = $this->get('campaignchain.job.operation.slideshare.publish_slideshow');
            //$job->execute($operation->getId());
            
            $this->get('session')->getFlashBag()->add(
                'success',
                'The slideshow <a href="'.$this->generateUrl('campaignchain_core_activity_edit', array('id' => $activity->getId())).'">'.$activity->getName().'</a> has been edited successfully.'
            );

            // Status Update to be sent immediately?
            // TODO: This is an intermediary hardcoded hack and should be instead handled by the scheduler.
            if ($form->get('campaignchain_hook_campaignchain_due')->has('execution_choice') && $form->get('campaignchain_hook_campaignchain_due')->get('execution_choice')->getData() == 'now') {
                $job = $this->get('campaignchain.job.operation.slideshare.publish_slideshow');
                $job->execute($operation->getId());
                // TODO: Add different flashbag which includes link to published slideshow
            }

            return $this->redirect($this->generateUrl('campaignchain_core_activities'));
            
        }
             
        return $this->render(
            'CampaignChainOperationSlideShareBundle::edit.html.twig',
            array(
                'page_title' => $activity->getName(),
                'activity' => $activity,
                'slideshow' => $slideshowOperation,
                'slideshow_embed' => $xml->Embed,
                'form' => $form->createView(),
                'form_submit_label' => 'Save',
                'form_cancel_route' => 'campaignchain_core_activities'
            ));
        
    }

    public function editModalAction(Request $request, $id)
    {
        $activityService = $this->get('campaignchain.core.activity');
        $activity = $activityService->getActivity($id);
        $campaign = $activity->getCampaign();

        // Get the one operation.
        $operation = $activityService->getOperation($id);

        $slideshowOperation = $this->getDoctrine()
            ->getRepository('CampaignChainOperationSlideShareBundle:Slideshow')
            ->findOneByOperation($operation);

        if (!$slideshowOperation) {
            throw new \Exception(
                'No slideshow found for Operation with ID '.$operation->getId()
            );
        }

        $client = $this->get('campaignchain.channel.slideshare.rest.client');
        $connection = $client->connectByActivity($activity);
        $xml = $connection->getSlideshowById($slideshowOperation->getIdentifier());

        /*
         * If the slideshow embed option was revoked on SlideShare.com, then
         * configure it to work again, so that we can display the slideshow
         * within CampaignChain.
         */
        if($xml->AllowEmbed == 0) {
            $returnXML = $connection->allowEmbedsUserSlideshow($xml->ID);
            print_r($returnXML);
        }

        $activityType = $this->get('campaignchain.core.form.type.activity');
        $activityType->setBundleName(self::ACTIVITY_BUNDLE_NAME);
        $activityType->setModuleIdentifier(self::ACTIVITY_MODULE_IDENTIFIER);
        $activityType->showNameField(false);
        $activityType->setCampaign($campaign);

        $form = $this->createForm($activityType, $activity);

        $form->handleRequest($request);

        return $this->render(
            'CampaignChainOperationSlideShareBundle::edit_modal.html.twig',
            array(
                'page_title' => $activity->getName(),
                'operation' => $operation,
                'activity' => $activity,
                'form' => $form->createView(),
                'slideshow' => $slideshowOperation,
                'slideshow_embed' => $xml->Embed,
                'show_date' => true,
            ));
    }

    public function editApiAction(Request $request, $id)
    {
        $responseData = array();

        $data = $request->get('campaignchain_core_activity');

        //$responseData['payload'] = $data;

        $activityService = $this->get('campaignchain.core.activity');
        $activity = $activityService->getActivity($id);

        $hookService = $this->get('campaignchain.core.hook');
        $activity = $hookService->processHooks(self::ACTIVITY_BUNDLE_NAME, self::ACTIVITY_MODULE_IDENTIFIER, $activity, $data);

        $repository = $this->getDoctrine()->getManager();
        $repository->persist($activity);
        $repository->flush();

        $responseData['start_date'] =
        $responseData['end_date'] =
            $activity->getStartDate()->format(\DateTime::ISO8601);

        $encoders = array(new JsonEncoder());
        $normalizers = array(new GetSetMethodNormalizer());
        $serializer = new Serializer($normalizers, $encoders);

        $response = new Response($serializer->serialize($responseData, 'json'));
        return $response->setStatusCode(Response::HTTP_OK);
    }

    public function readAction(Request $request, $id)
    {
        $activityService = $this->get('campaignchain.core.activity');
        $activity = $activityService->getActivity($id);

        // Get the one operation.
        $operation = $activityService->getOperation($id);

        // Get the slideshow details
        $slideshow = $this->getDoctrine()
            ->getRepository('CampaignChainOperationSlideShareBundle:Slideshow')
            ->findOneByOperation($operation);

        if (!$slideshow) {
            throw new \Exception(
                'No slideshow found for Operation with ID '.$operation->getId()
            );
        }
            
        $client = $this->get('campaignchain.channel.slideshare.rest.client');
        $connection = $client->connectByActivity($activity);
        $xml = $connection->getSlideshowById($slideshow->getIdentifier());
        
        return $this->render(
            'CampaignChainOperationSlideShareBundle::read.html.twig',
            array(
                'page_title' => $activity->getName(),
                'operation' => $operation,
                'activity' => $activity,
                'slideshow' => $slideshow,
                'slideshow_embed' => $xml->Embed,
                'show_date' => true,
        ));
    
    }

    public function readModalAction(Request $request, $id)
    {
        $activityService = $this->get('campaignchain.core.activity');
        $activity = $activityService->getActivity($id);

        // Get the one operation.
        $operation = $activityService->getOperation($id);

        // Get the slideshow details
        $slideshow = $this->getDoctrine()
            ->getRepository('CampaignChainOperationSlideShareBundle:Slideshow')
            ->findOneByOperation($operation);

        if (!$slideshow) {
            throw new \Exception(
                'No slideshow found for Operation with ID '.$operation->getId()
            );
        }

        $client = $this->get('campaignchain.channel.slideshare.rest.client');
        $connection = $client->connectByActivity($activity);
        $xml = $connection->getSlideshowById($slideshow->getIdentifier());

        return $this->render(
            'CampaignChainOperationSlideShareBundle::read_modal.html.twig',
            array(
                'page_title' => $activity->getName(),
                'operation' => $operation,
                'activity' => $activity,
                'slideshow' => $slideshow,
                'slideshow_embed' => $xml->Embed,
                'show_date' => true,
            ));
    }
}
