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
        
        // retrieve slide decks
        $client = $this->get('campaignchain.channel.slideshare.rest.client');
        $connection = $client->connectByLocation($location);
        $xml = $connection->getUserSlideshows();

        
        if($upcomingNewsletters['total'] == 0){
            $this->get('session')->getFlashBag()->add(
                'warning',
                'No upcoming newsletter campaigns available.'
            );

            return $this->redirect(
                $this->generateUrl('campaignchain_core_activities_new')
            );
        }

        $form->handleRequest($request);

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
