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
use CampaignChain\CoreBundle\Controller\Module\AbstractActivityHandler;
use CampaignChain\CoreBundle\EntityService\LocationService;
use CampaignChain\Operation\SlideShareBundle\Job\PublishSlideshow;
use Doctrine\ORM\EntityManager;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Bundle\TwigBundle\TwigEngine;
use Symfony\Component\Form\Form;
use Symfony\Component\HttpFoundation\Session\Session;
use CampaignChain\CoreBundle\Entity\Operation;
use CampaignChain\CoreBundle\Entity\Activity;
use CampaignChain\CoreBundle\Entity\Location;
use CampaignChain\CoreBundle\Entity\Campaign;
use CampaignChain\Operation\SlideShareBundle\Entity\Slideshow;
use CampaignChain\Operation\SlideShareBundle\EntityService\Slideshow as SlideshowService;

class SlideShareHandler extends AbstractActivityHandler
{
    const LOCATION_BUNDLE_NAME          = 'campaignchain/location-slideshare';
    const LOCATION_MODULE_IDENTIFIER    = 'campaignchain-slideshare-user';

    protected $em;
    protected $router;
    protected $contentService;
    protected $locationService;
    protected $restClient;
    protected $job;
    protected $session;
    protected $templating;
    protected $availableSlideshows;
    private     $remoteSlideshow;
    private     $restApiConnection;

    public function __construct(
        EntityManager $em,
        SlideshowService $contentService,
        LocationService $locationService,
        SlideShareClient $restClient,
        PublishSlideshow $job,
        $session,
        TwigEngine $templating,
        Router $router
    )
    {
        $this->em = $em;
        $this->contentService   = $contentService;
        $this->locationService  = $locationService;
        $this->restClient       = $restClient;
        $this->job              = $job;
        $this->session          = $session;
        $this->templating       = $templating;
        $this->router           = $router;
    }

    public function createContent(Location $location = null, Campaign $campaign = null)
    {
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
    }

    public function processContent(Operation $operation, $data)
    {
        if(isset($data['slideshow'])) {
            $sid = $data['slideshow'];

            $content = new Slideshow();
            $content->setOperation($operation);
            $content->setUrl($this->availableSlideshows[$sid]['url']);
            $content->setIdentifier($sid);
            $content->setTitle($this->availableSlideshows[$sid]['title']);

            return $content;
        } else {
            throw new \Exception('SlideShow details cannot be edited.');
        }
    }

    public function readAction(Operation $operation)
    {
        // Get the slideshow details
        $slideshow = $this->contentService->getSlideshowByOperation($operation);

        $connection = $this->restClient->connectByActivity($operation->getActivity());
        $xml = $connection->getSlideshowById($slideshow->getIdentifier());

        return $this->templating->renderResponse(
            'CampaignChainOperationSlideShareBundle::read.html.twig',
            array(
                'page_title' => $operation->getActivity()->getName(),
                'operation' => $operation,
                'activity' => $operation->getActivity(),
                'slideshow' => $slideshow,
                'slideshow_embed' => $xml->Embed,
                'show_date' => true,
            ));
    }

    public function processActivity(Activity $activity, $data)
    {
        $sid = $data['slideshow'];
        $title = $this->availableSlideshows[$sid]['title'];
        $activity->setName($title);

        return $activity;
    }

    public function processContentLocation(Location $location, $data)
    {
        $sid = $data['slideshow'];
        $location->setIdentifier($sid);
        $location->setName($this->availableSlideshows[$sid]['title']);
        $location->setUrl($this->availableSlideshows[$sid]['url']);

        return $location;
    }

    public function postPersistNewEvent(Operation $operation, Form $form, $content = null)
    {
        // Content to be published immediately?
        if (!$this->publishNow($operation, $form)){
            $this->makeRemoteSlideshowEmbeddable($operation);
        }
    }

    public function postPersistEditEvent(Operation $operation, Form $form, $content = null)
    {
        // Content to be published immediately?
        $this->publishNow($operation, $form);
    }

    public function preFormSubmitEditEvent(Operation $operation)
    {
        $this->makeRemoteSlideshowEmbeddable($operation);
    }

    public function preFormSubmitEditModalEvent(Operation $operation)
    {
        $this->makeRemoteSlideshowEmbeddable($operation);
    }

    public function getEditRenderOptions(Operation $operation)
    {
        $content = $this->contentService->getSlideshowByOperation($operation);
        $remoteSlideshow = $this->getRemoteSlideshow($operation, $content->getIdentifier());

        return array(
            'template' => 'CampaignChainOperationSlideShareBundle::edit.html.twig',
            'vars' => array(
                'slideshow' => $content,
                'slideshow_embed' => $remoteSlideshow->Embed
            )
        );
    }

    public function getEditModalRenderOptions(Operation $operation)
    {
        $content = $this->contentService->getSlideshowByOperation($operation);
        $remoteSlideshow = $this->getRemoteSlideshow($operation, $content->getIdentifier());

        return array(
            'template' => 'CampaignChainOperationSlideShareBundle::edit_modal.html.twig',
            'vars' => array(
                'slideshow' => $content,
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
    private function makeRemoteSlideshowEmbeddable(Operation $operation)
    {
        $content = $this->contentService->getSlideshowByOperation($operation);

        $remoteSlideshow = $this->getRemoteSlideshow(
            $operation, $content->getIdentifier()
        );
        if($remoteSlideshow->AllowEmbed == 0) {
            $connection = $this->getRestApiConnectionByOperation($operation);
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

    public function hasContent($view)
    {
        if($view != 'new'){
            return false;
        }

        return true;
    }

    private function publishNow(Operation $operation, Form $form)
    {
        if ($form->get('campaignchain_hook_campaignchain_due')->has('execution_choice') && $form->get('campaignchain_hook_campaignchain_due')->get('execution_choice')->getData() == 'now') {
            $this->job->execute($operation->getId());
            $content = $this->contentService->getSlideshowByOperation($operation);
            $this->session->getFlashBag()->add(
                'success',
                'The slide show was published. <a href="'.$content->getUrl().'">View it on SlideShare.net</a>.'
            );

            return true;
        }

        return false;
    }
}