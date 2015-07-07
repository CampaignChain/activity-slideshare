<?php
/*
 * This file is part of the CampaignChain package.
 *
 * (c) Sandro Groganz <sandro@campaignchain.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CampaignChain\Activity\SlideShareBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use CampaignChain\Location\SlideShareBundle\Entity\SlideShareUser;
use CampaignChain\Operation\SlideShareBundle\Form\Type\SlideShareOperationType;
use CampaignChain\CoreBundle\Entity\Operation;
use CampaignChain\CoreBundle\Entity\Location;
use CampaignChain\CoreBundle\Entity\Medium;
use CampaignChain\Operation\SlideShareBundle\Entity\Slideshow;

class SlideShareController extends Controller
{

    const ACTIVITY_BUNDLE_NAME          = 'campaignchain/activity-slideshare';
    const ACTIVITY_MODULE_IDENTIFIER    = 'campaignchain-slideshare';
    const OPERATION_BUNDLE_NAME         = 'campaignchain/operation-slideshare';
    const OPERATION_MODULE_IDENTIFIER   = 'campaignchain-slideshare';
    const LOCATION_BUNDLE_NAME          = 'campaignchain/location-slideshare';
    const LOCATION_MODULE_IDENTIFIER    = 'campaignchain-slideshare';
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
        if ($privateSlideshowCount == 0){
            $this->get('session')->getFlashBag()->add(
                'warning',
                'No private slideshows available.'
            );

            return $this->redirect(
                $this->generateUrl('campaignchain_core_activities_new')
            );
        } 

        $activityType = $this->get('campaignchain.core.form.type.activity');
        $activityType->setBundleName(self::ACTIVITY_BUNDLE_NAME);
        $activityType->setModuleIdentifier(self::ACTIVITY_MODULE_IDENTIFIER);
        $activityType->showNameField(false);

        $operationType = new SlideShareOperationType($this->getDoctrine()->getManager(), $this->get('service_container'));

        $location = $locationService->getLocation($location->getId());

        // Have the location point to the list of newsletters in the
        // MailChimp website's admin area.
        //$location->setUrl(self::LINK_ADMIN_CAMPAIGNS);

        $operationType->setLocation($location);

        foreach ($slideshows as $key => $value) {
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
            $title = $slideshows[$sid]['title'];
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

            // The Operation creates a Location, i.e. the newsletter
            // will be accessible through an archive URL after publishing.
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
            $location->setName($slideshows[$sid]['title']);
            $location->setUrl($slideshows[$sid]['url']);
            $location->setStatus(Medium::STATUS_UNPUBLISHED);
            $location->setOperation($operation);
            $operation->addLocation($location);

            $slideshowOperation = new Slideshow();
            $slideshowOperation->setOperation($operation);
            $slideshowOperation->setUrl($slideshows[$sid]['url']);
            
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

                // Schedule the newsletter on MailChimp.
                /*
                $client->campaigns->schedule(
                    $newsletterOperation->getCampaignId(),
                    $newsletterOperation->getSendTime()->format(self::MAILCHIMP_DATETIME_FORMAT)
                );
                */

                $repository->getConnection()->commit();
            } catch (\Exception $e) {
                $repository->getConnection()->rollback();
                throw $e;
            }

            $this->get('session')->getFlashBag()->add(
                'success',
                'The slideshow <a href="'.$this->generateUrl('campaignchain_core_activity_edit', array('id' => $activity->getId())).'">'.$activity->getName().'</a> has been added successfully.'
            );

            return $this->redirect($this->generateUrl('campaignchain_core_activities'));
            
        }
        
        return $this->render(
            'CampaignChainCoreBundle:Operation:new.html.twig',
            array(
                'page_title' => 'Activate Slideshow',
                'activity' => $activity,
                'campaign' => $campaign,
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
    
    }

    public function editModalAction(Request $request, $id)
    {
    
    }

    public function editApiAction(Request $request, $id)
    {
    
    }

    public function readAction(Request $request, $id)
    {
    
    }
}
