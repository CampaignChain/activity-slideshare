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

use CampaignChain\Channel\SlideShareBundle\REST\SlideShareClient;
use CampaignChain\CoreBundle\Controller\Module\AbstractActivityModuleHandler;
use CampaignChain\CoreBundle\EntityService\LocationService;
use Doctrine\ORM\EntityManager;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Bundle\TwigBundle\TwigEngine;
use Symfony\Component\HttpFoundation\Session\Session;
use CampaignChain\CoreBundle\Entity\Operation;
use CampaignChain\CoreBundle\Entity\Activity;
use CampaignChain\CoreBundle\Entity\Location;
use CampaignChain\Operation\SlideShareBundle\Entity\Slideshow;
use CampaignChain\Operation\SlideShareBundle\EntityService\Slideshow as SlideshowService;

class SlideShareHandler extends AbstractActivityModuleHandler
{
    const LOCATION_BUNDLE_NAME          = 'campaignchain/location-slideshare';
    const LOCATION_MODULE_IDENTIFIER    = 'campaignchain-slideshare-user';

    protected $router;
    protected $detailService;
    protected $locationService;
    protected $restClient;
    protected $em;
    protected $session;
    protected $templating;
    protected $availableSlideshows;
    private     $remoteSlideshow;
    private     $restApiConnection;

    public function __construct(
        EntityManager $em,
        SlideshowService $detailService,
        LocationService $locationService,
        SlideShareClient $restClient,
        $session,
        TwigEngine $templating,
        Router $router
    )
    {
        $this->router = $router;
        $this->detailService = $detailService;
        $this->locationService = $locationService;
        $this->restClient = $restClient;
        $this->em = $em;
        $this->session = $session;
        $this->templating = $templating;
    }

    public function getOperationDetail(Location $location, Operation $operation = null)
    {
        if(!$operation) {
            // Retrieve slide decks from slideshare.net via REST API.
            $connection = $this->getRestApiConnectionByLocation($location);
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
                $this->session->getFlashBag()->add(
                    'warning',
                    'No private slideshows found.'
                );

                header('Location: '.$this->router->generate('campaignchain_core_activities_new'));
                exit;
            }

            // check that available private slideshows are not already in use
            $this->availableSlideshows = array();
            if ($privateSlideshowCount > 0) {

                foreach($slideshows as $key => $value) {
                    if (!$this->locationService->existsInAllCampaigns(
                        self::LOCATION_BUNDLE_NAME, self::LOCATION_MODULE_IDENTIFIER, $key
                    )) {
                        $this->availableSlideshows[$key] = $value;
                    }
                }

                if (!count($this->availableSlideshows)) {
                    $this->session->getFlashBag()->add(
                        'warning',
                        'All available private slideshows have already been added to campaigns.'
                    );

                    header('Location: '.$this->router->generate('campaignchain_core_activities_new'));
                    exit;
                }
            }

            foreach ($this->availableSlideshows as $key => $value) {
                $formDataSlideshowsArr[$key] = $value['title'];
            }

            return $formDataSlideshowsArr;
        } else {
//            $operationDetails = $this->detailService->getSlideshowByOperation($operation);
//
//            $this->makeRemoteSlideshowEmbeddable(
//                $operation, $operationDetails->getIdentifier()
//            );
//
//            return $operationDetails;

            return null;
        }
    }

    public function processOperationDetails(Operation $operation, $data)
    {
        if(isset($data['slideshow'])) {
            $sid = $data['slideshow'];

            $operationDetails = new Slideshow();
            $operationDetails->setOperation($operation);
            $operationDetails->setUrl($this->availableSlideshows[$sid]['url']);
            $operationDetails->setIdentifier($sid);
            $operationDetails->setTitle($this->availableSlideshows[$sid]['title']);

            return $operationDetails;
        } else {
            throw new \Exception('SlideShow details cannot be edited.');
        }
    }

    public function readOperationDetailsAction(Operation $operation)
    {
        $status = $this->detailService->getStatusByOperation($operation);

        // Connect to Twitter REST API
        $connection = $this->restClient->connectByActivity($operation->getActivity());

        $isProtected = false;
        $notAccessible = false;

        try {
            $request = $connection->get('statuses/oembed.json?id='.$status->getIdStr());
            $response = $request->send()->json();
            $message = $response['html'];
        } catch (\Exception $e) {
            // Check whether it is a protected tweet.
            if(
                'Forbidden' == $e->getResponse()->getReasonPhrase() &&
                '403'       == $e->getResponse()->getStatusCode()
            ){
                $this->session->getFlashBag()->add(
                    'warning',
                    'This is a protected tweet.'
                );
                $message = $status->getMessage();
            } else {
//                    throw new \Exception(
//                        'TWitter API error: '.
//                        'Reason: '.$e->getResponse()->getReasonPhrase().','.
//                        'Status: '.$e->getResponse()->getStatusCode().','
//                    );
                $this->session->getFlashBag()->add(
                    'warning',
                    'This Tweet might not have been published yet.'
                );
                $message = $status->getMessage();
                $notAccessible = true;
            }
        }

        $locationTwitter = $this->em
            ->getRepository('CampaignChainLocationTwitterBundle:TwitterUser')
            ->findOneByLocation($operation->getActivity()->getLocation());

        $tweetUrl = $status->getUrl();

        return $this->templating->renderResponse(
            'CampaignChainOperationTwitterBundle::read.html.twig',
            array(
                'page_title' => $operation->getActivity()->getName(),
                'tweet_is_protected' => $isProtected,
                'tweet_not_accessible' => $notAccessible,
                'message' => $message,
                'status' => $status,
                'activity' => $operation->getActivity(),
                'activity_date' => $operation->getActivity()->getStartDate()->format(self::DATETIME_FORMAT_TWITTER),
                'location_twitter' => $locationTwitter,
            ));
    }

    public function processActivity(Activity $activity, $data)
    {
        $sid = $data['slideshow'];
        $title = $this->availableSlideshows[$sid]['title'];
        $activity->setName($title);

        return $activity;
    }

    public function processOperationLocation(Location $location, $data)
    {
        $sid = $data['slideshow'];
        $location->setIdentifier($sid);
        $location->setName($this->availableSlideshows[$sid]['title']);
        $location->setUrl($this->availableSlideshows[$sid]['url']);

        return $location;
    }

    public function postPersistNewAction(Operation $operation)
    {
        $operationDetails = $this->detailService->getSlideshowByOperation($operation);

        $this->makeRemoteSlideshowEmbeddable(
            $operation, $operationDetails->getIdentifier());
    }

    public function preFormCreateEditAction(Operation $operation)
    {
        $this->postPersistNewAction($operation);
    }

    public function preFormCreateEditModalAction(Operation $operation)
    {
        $this->postPersistNewAction($operation);
    }

    public function getRenderOptionsEditAction(Operation $operation)
    {
        $operationDetails = $this->detailService->getSlideshowByOperation($operation);
        $remoteSlideshow = $this->getRemoteSlideshow($operation, $operationDetails->getIdentifier());

        return array(
            'template' => 'CampaignChainOperationSlideShareBundle::edit.html.twig',
            'vars' => array(
                'slideshow' => $operationDetails,
                'slideshow_embed' => $remoteSlideshow->Embed
            )
        );
    }

    public function getRenderOptionsEditModalAction(Operation $operation)
    {
        $operationDetails = $this->detailService->getSlideshowByOperation($operation);
        $remoteSlideshow = $this->getRemoteSlideshow($operation, $operationDetails->getIdentifier());

        return array(
            'template' => 'CampaignChainOperationSlideShareBundle::edit_modal.html.twig',
            'vars' => array(
                'slideshow' => $operationDetails,
                'slideshow_embed' => $remoteSlideshow->Embed,
                'operation' => $operation,
                'activity' => $operation->getActivity(),
            )
        );
    }

    /**
     * If the slideshow embed option was revoked on SlideShare.com, then
     * configure it to work again, so that we can display the slideshow
     * within CampaignChain.
     *
     * @param Operation $operation
     * @param string $identifier
     */
    private function makeRemoteSlideshowEmbeddable(Operation $operation, $identifier)
    {
        $remoteSlideshow = $this->getRemoteSlideshow($operation, $identifier);
        if($remoteSlideshow->AllowEmbed == 0) {
            $connection = $this->getRestApiConnectionByOperation($operation);
            $connection = $this->getRestApiConnection($operation);
            $connection->allowEmbedsUserSlideshow($remoteSlideshow->ID);
        }
    }

    private function getRestApiConnectionByOperation(Operation $operation)
    {
        if(!$this->restApiConnection){
            $this->restApiConnection = $this->restClient->connectByActivity(
                $operation->getActivity()
            );
        }

        return $this->restApiConnection;
    }

    private function getRestApiConnectionByLocation(Location $location)
    {
        if(!$this->restApiConnection){
            $this->restApiConnection =
                $this->restClient->connectByLocation($location);
        }

        return $this->restApiConnection;
    }

    private function getRemoteSlideshow(Operation $operation, $identifier){
        if(!$this->remoteSlideshow){
            $connection = $this->getRestApiConnectionByOperation($operation);
            $this->remoteSlideshow = $connection->getSlideshowById(
                $identifier
            );
        }

        return $this->remoteSlideshow;
    }

    public function hasOperationForm($view)
    {
        if($view != 'new'){
            return false;
        }

        return true;
    }
}