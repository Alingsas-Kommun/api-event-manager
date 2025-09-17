<?php

namespace HbgEventImporter\Parser;

use \HbgEventImporter\Event as Event;
use \HbgEventImporter\Location as Location;
use \HbgEventImporter\Organizer as Organizer;

ini_set('memory_limit', '256M');
ini_set('default_socket_timeout', 60 * 10);

class VastSverige extends \HbgEventImporter\Parser
{
    private $shortKey;
    private $categories = [];

    public function __construct($url, $apiKeys)
    {
        parent::__construct($url, $apiKeys);
        // Set unique key on events
        $this->shortKey = substr(md5($url), 0, 8);
    }

    /**
     * Start parser
     * @return void
     */
    public function start()
    {
        $page = 0;
        $loop = true;

        $this->collectDataForLevenshtein();

        $this->fetchCategories();

        // Prepare the POST data
        $postData = json_encode([
            'Query' => '',
            'Take' => 1000,
            'Sort' => 'Date',
            'Skip' => 0,
            'SearchTypes' => 'event',
            'IDOnly' => true,
            'Filter' => []
        ]);

        // Use wp_remote_post to fetch event IDs
        $post_url = $this->url . '/get/' . $this->apiKeys['config'];

        $response = wp_remote_post($post_url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => $postData,
            'timeout' => 60 * 10
        ));

        if (is_wp_error($response)) {
            error_log('Error fetching events: ' . $response->get_error_message());
            return;
        }

        $eventData = wp_remote_retrieve_body($response);
        $eventData = json_decode($eventData);

        // Return if result is empty
        if (empty($eventData) || empty($eventData->IDs)) {
            return;
        }

        // Store all events by heading for merging
        $eventsByHeading = [];

        // Fetch detailed data for each event
        foreach ($eventData->IDs as $eventId) {
            if (!isset($eventId) || empty($eventId)) {
                continue;
            }

            // Fetch detailed data for this event
            $detailUrl = $this->url . '/detail/sv/' . $eventId;
            $detailResponse = wp_remote_get($detailUrl, array(
                'timeout' => 60 * 10
            ));

            if (is_wp_error($detailResponse)) {
                error_log('Error fetching event details: ' . $detailResponse->get_error_message());
                continue;
            }

            $detailData = wp_remote_retrieve_body($detailResponse);
            $detailData = json_decode($detailData);

            if (empty($detailData)) {
                continue;
            }

            $heading = $detailData->Heading;
            
            if (!isset($eventsByHeading[$heading])) {
                $eventsByHeading[$heading] = $detailData;
            } else {
                if (!empty($detailData->Dates)) {
                    if (!isset($eventsByHeading[$heading]->Dates)) {
                        $eventsByHeading[$heading]->Dates = [];
                    }
                    $eventsByHeading[$heading]->Dates = array_merge(
                        $eventsByHeading[$heading]->Dates,
                        $detailData->Dates
                    );
                }
            }
        }

        foreach ($eventsByHeading as $event) {
            $this->saveEvent($event);
        }
    }

    /**
     * Cleans a single events data into correct format and saves it to db
     * @param  object $eventData Event data
     * @throws \Exception
     * @return void
     */
    public function saveEvent($eventData)
    {
        // Deletes event from db
        if (isset($eventData->changeType) && $eventData->changeType === 'Deleted') {
            global $wpdb;
            $eventManagerUid = $this->getEventUid($eventData->Id);
            // Check if the post exist in db
            $result = $wpdb->get_row("select post_id from $wpdb->postmeta where meta_value = '{$eventManagerUid}'", ARRAY_N);
            // Delete the event if it exists
            if (isset($result[0]) && is_numeric($result[0])) {
                wp_trash_post((int)$result[0]);
            }

            return;
        }

        $data['uId'] = $eventData->Id;
        $data['postTitle'] = !empty($eventData->Heading) ? strip_tags($eventData->Heading) : null;
        $data['postContent'] = !empty($eventData->Description) ? $eventData->Description : '';
        $data['image'] = !empty($eventData->Image->Path) ? $eventData->Image->Path : null;
        $data['event_link'] = !empty($eventData->Url) ? 'https://www.vastsverige.com' . $eventData->Url : null;
        $data['postStatus'] = 'draft';
        $data['postStatus'] = get_field('vastsverige_post_status', 'option') ? get_field('vastsverige_post_status', 'option') : 'draft';
        $data['userGroups'] = null;
        $data['categories'] = isset($eventData->Categories->Event) && is_array($eventData->Categories->Event) ? 
            $this->convertCategoryIdsToNames($eventData->Categories->Event) : array();
        $data['facebook_link'] = !empty($eventData->FacebookLink) ? $eventData->FacebookLink : null;
        $data['instagram_link'] = !empty($eventData->InstagramLink) ? $eventData->InstagramLink : null;
        $data['contact_phone'] = !empty($eventData->Contact->Phone) ? $eventData->Contact->Phone : null;
        $data['contact_email'] = !empty($eventData->Contact->Email) ? $eventData->Contact->Email : null;
        $data['location'] = null;
        
        $data['occasions'] = array();
        if (!empty($eventData->Dates)) {
            foreach ($eventData->Dates as $date) {
                $start = new \DateTime($date->Date);
                $start = $start->format('Y-m-d H:i:s');

                // Calculate end time based on event duration
                $end = new \DateTime($date->Date);
                if ($eventData->End) {
                    $end = new \DateTime($eventData->End);
                }
                $end = $end->format('Y-m-d H:i:s');

                // Add latitude and longitude to Contact object if they exist
                if (isset($eventData->Position->lat)) {
                    $eventData->Contact->latitude = $eventData->Position->lat;
                }
                if (isset($eventData->Position->lng)) {
                    $eventData->Contact->longitude = $eventData->Position->lng;
                }

                $locationId = !empty($eventData->Contact) ? $this->maybeCreateLocation($eventData->City, $data['userGroups'], $data['postStatus']) : null;

                $data['location'] = $locationId;

                $data['occasions'][] = array(
                    'start_date' => $start,
                    'end_date' => $end,
                    'door_time' => $start,
                    'status' => 'scheduled',
                    'location_mode' => !empty($locationId) ? 'custom' : 'master',
                    'location' => !empty($locationId) ? $locationId : null,
                );
            }
        }
        
        // Create organizer from contact data
        $organizerId = !empty($eventData->Contact) ? $this->maybeCreateOrganizer($eventData->Contact, $data['userGroups'], $data['postStatus']) : null;
        
        $this->maybeCreateEvent($data, $organizerId);
    }

    /**
     * Creates or updates an event if possible
     * @param  array  $data       Event data
     * @param  int    $organizerId Organizer ID
     * @return boolean|int          Event id or false
     * @throws \Exception
     */
    public function maybeCreateEvent($data, $organizerId = null)
    {
        $eventId = $this->checkIfPostExists('event', $data['postTitle']);
        $occurred = false;

        $eventManagerUid = (get_post_meta($eventId, '_event_manager_uid', true)) ? get_post_meta(
            $eventId,
            '_event_manager_uid',
            true
        ) : $this->getEventUid($data['uId']);
        $postStatus = $data['postStatus'];
        // Get existing event meta data
        $sync = true;
        if ($eventId) {
            $sync = get_post_meta($eventId, 'sync', true);
            $postStatus = get_post_status($eventId);
            $levenshteinKey = array_search($eventId, array_column($this->levenshteinTitles['event'], 'ID'));
            $occurred = $this->levenshteinTitles['event'][$levenshteinKey]['occurred'];
        }

        // Bail if api sync id disabled
        if ($eventId && !$sync) {
            return $eventId;
        }

        try {
            $event = new Event(
                array(
                    'post_title' => $data['postTitle'],
                    'post_content' => $data['postContent'],
                    'post_status' => $postStatus,
                ),
                array(
                    '_event_manager_uid' => $eventManagerUid,
                    'sync' => 1,
                    'status' => 'Active',
                    'image' => $data['image'],
                    'event_link' => $data['event_link'],
                    'facebook' => $data['facebook_link'],
                    'instagram' => $data['instagram_link'],
                    'categories' => $data['categories'],
                    'occasions' => $data['occasions'],
                    'contact_phone' => $data['contact_phone'],
                    'contact_email' => $data['contact_email'],
                    'location' => $data['location'] ?? null,
                    'organizer' => !empty($organizerId) ? array(array(
                        'main_organizer' => true,
                        'organizer' => intval($organizerId)
                    )) : null,
                    'booking_link' => null,
                    'booking_phone' => null,
                    'age_restriction' => null,
                    'price_information' => null,
                    'price_adult' => null,
                    'price_children' => null,
                    'import_client' => 'vast-sverige',
                    'imported_post' => 1,
                    'user_groups' => $data['userGroups'],
                    'occurred' => $occurred,
                    'ticket_stock' => null,
                    'ticket_release_date' => null,
                    'tickets_remaining' => null,
                    'additional_ticket_retailers' => null,
                    'price_range_seated_minimum_price' => null,
                    'price_range_seated_maximum_price' => null,
                    'price_range_standing_minimum_price' => null,
                    'price_range_standing_maximum_price' => null,
                    'additional_ticket_types' => null,
                    'internal_event' => 0
                )
            );
        } catch (\Exception $e) {
            error_log($e);
            return false;
        }

        if (!$event->save()) {
            return false;
        }

        if (!$eventId) {
            $this->levenshteinTitles['event'][] = array(
                'ID' => $event->ID,
                'post_title' => $data['postTitle'],
                'occurred' => true,
            );
        } else {
            $this->levenshteinTitles['event'][$levenshteinKey]['occurred'] = true;
        }

        if (!is_null($event->image)) {
            $imageId = $event->setFeaturedImageFromUrl($event->image);

            if ($imageId) {
                update_post_meta($imageId, '_wp_attachment_image_alt', $data['postTitle']);
            }
        }

        return $event->ID;
    }

    /**
     * Creates or updates a location if possible
     *
     * @param [string] $cityName City name
     * @param [array] $userGroups User groups
     * @param [string] $locationPostStatus Post status
     * @return boolean|int  Location id or false
     * @throws \Exception
     */
    public function maybeCreateLocation($cityName, $userGroups, $locationPostStatus)
    {
        // Bail if city name is empty
        if (empty($cityName)) {
            return false;
        }

        $postTitle = $cityName;
        // Checking if there is a location already with this title or similar enough
        $locationId = $this->checkIfPostExists('location', $postTitle);
        $isUpdate = false;
        $uid = $this->getEventUid($cityName);

        // Check if this is a duplicate or update and if "sync" option is set.
        if ($locationId && get_post_meta($locationId, '_event_manager_uid', true)) {
            $existingUid = get_post_meta($locationId, '_event_manager_uid', true);
            $sync = get_post_meta($locationId, 'sync', true);
            $locationPostStatus = get_post_status($locationId);

            if ($existingUid == $uid && $sync == 1) {
                $isUpdate = true;
            }
        }

        if ($locationId && !$isUpdate) {
            return $locationId;
        }

        // Create the location
        try {
            $location = new Location(
                array(
                    'post_title' => $postTitle,
                    'post_status' => $locationPostStatus,
                ),
                array(
                    'import_client' => 'vast-sverige',
                    '_event_manager_uid' => $uid,
                    'user_groups' => $userGroups,
                    'sync' => 1,
                    'imported_post' => 1,
                )
            );
        } catch (\Exception $e) {
            $location = false;
            error_log($e);
        }

        if (!$location->save()) {
            if ($locationId) {
                return $locationId;
            } else {
                return false;
            }
        }

        $this->levenshteinTitles['location'][] = array('ID' => $location->ID, 'post_title' => $postTitle);

        return $location->ID;
    }

    /**
     * Creates or updates an organizer if possible
     *
     * @param [object] $data Data object
     * @param [array] $userGroups User groups
     * @param [string] $organizerPostStatus Post status
     * @return boolean|int  Organizer id or false
     * @throws \Exception
     */
    public function maybeCreateOrganizer($data, $userGroups, $organizerPostStatus)
    {
        // Bail if essential data is missing
        if (empty($data->CompanyName) && empty($data->Email)) {
            return false;
        }

        $postTitle = !empty($data->CompanyName) ? $data->CompanyName : $data->Email;
        // Checking if there is an organizer already with this title or similar enough
        $organizerId = $this->checkIfPostExists('organizer', $postTitle);
        $isUpdate = false;
        $uid = $this->getEventUid($data->CompanyName ?: $data->Email);

        // Check if this is a duplicate or update and if "sync" option is set.
        if ($organizerId && get_post_meta($organizerId, '_event_manager_uid', true)) {
            $existingUid = get_post_meta($organizerId, '_event_manager_uid', true);
            $sync = get_post_meta($organizerId, 'sync', true);
            $organizerPostStatus = get_post_status($organizerId);

            if ($existingUid == $uid && $sync == 1) {
                $isUpdate = true;
            }
        }

        if ($organizerId && !$isUpdate) {
            return $organizerId;
        }

        // Create the organizer
        try {
            $organizer = new Organizer(
                array(
                    'post_title' => $postTitle,
                    'post_status' => $organizerPostStatus,
                ),
                array(
                    'email' => $data->Email ?? null,
                    'phone' => $data->Phone ?? null,
                    'website' => $data->Website ?? null,
                    'import_client' => 'vast-sverige',
                    '_event_manager_uid' => $uid,
                    'user_groups' => $userGroups,
                    'sync' => 1,
                    'imported_post' => 1,
                )
            );
        } catch (\Exception $e) {
            error_log($e);
            if ($organizerId) {
                return $organizerId;
            } else {
                return false;
            }
        }

        if (!$organizer->save()) {
            if ($organizerId) {
                return $organizerId;
            } else {
                return false;
            }
        }

        $this->levenshteinTitles['organizer'][] = array('ID' => $organizer->ID, 'post_title' => $postTitle);

        return $organizer->ID;
    }

     /**
      * Returns event manager UID
     *
     * @param [mixed] $identifier
     * @return void
     */
    public function getEventUid($identifier)
    {
        return 'vast-sverige-' . $this->shortKey . '-' . $identifier;
    }

    /**
     * Fetch and store categories from API
     */
    private function fetchCategories()
    {
        $categoriesUrl = $this->url . '/categories';
        $response = wp_remote_get($categoriesUrl, array(
            'timeout' => 60 * 10
        ));

        if (is_wp_error($response)) {
            error_log('Error fetching categories: ' . $response->get_error_message());
            return;
        }

        $categoriesData = wp_remote_retrieve_body($response);
        $categoriesData = json_decode($categoriesData);

        if (!empty($categoriesData)) {
            foreach ($categoriesData as $categoryGroup) {
                if ($categoryGroup->id === 73) { // "Kategori" group
                    foreach ($categoryGroup->categories as $category) {
                        $this->categories[$category->id] = $category->text;
                    }
                    break;
                }
            }
        }
    }

    /**
     * Convert category IDs to names
     * @param array $categoryIds
     * @return array
     */
    private function convertCategoryIdsToNames($categoryIds)
    {
        $categoryNames = [];
        foreach ($categoryIds as $id) {
            if (isset($this->categories[$id])) {
                $categoryNames[] = $this->categories[$id];
            }
        }
        return array_values($categoryNames);
    }
}
