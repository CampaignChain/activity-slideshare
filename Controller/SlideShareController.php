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
