<?php

/**
 * Class HarvestApi
 *
 * @author Fabrizio Branca
 */
class HarvestApi
{

    protected $email;
    protected $password;
    protected $account;

    /**
     * @var Zend\Cache\Storage\StorageInterface
     */
    protected $cache;
    
    CONST API_URL = 'http://%s.harvestapp.com/';

    /**
     * Constructor
     * 
     * @param $email
     * @param $password
     * @param $account
     */
    public function __construct($email, $password, $account)
    {
        $this->email = $email;
        $this->password = $password;
        $this->account = $account;
    }

    /**
     * Set cache
     *
     * @param \Zend\Cache\Storage\StorageInterface $cache
     */
    public function setCache(Zend\Cache\Storage\StorageInterface $cache) {
        $this->cache = $cache;
    }

    /**
     * Add tracking
     * 
     * @param $notes
     * @param $hours
     * @param $projectId
     * @param $taskId
     * @param $date
     * @return mixed
     * @throws Exception
     */
    public function dailyAdd($notes, $hours, $projectId, $taskId, $date)
    {
        $timestamp = strtotime($date);
        if ($timestamp === false || $timestamp <= 0) {
            throw new Exception('Invalid timestamp');
        }
        $date = date('D, d M Y', $timestamp);
        $xml = "<request>
            <notes>$notes</notes>
            <hours>$hours</hours>
            <project_id>$projectId</project_id>
            <task_id>$taskId</task_id>
            <spent_at>$date</spent_at>
        </request>";
        return $this->doPostRequest($this->getApiUrl() . 'daily/add', $xml);
    }

    /**
     * Update hours
     *
     * @param $id
     * @param $hours
     * @return mixed
     */
    public function dailyUpdateHours($id, $hours)
    {
        $xml = "<request>
            <hours>$hours</hours>
        </request>";
        return $this->doPostRequest($this->getApiUrl() . 'daily/update/' . $id, $xml);
    }

    /**
     * Get entries for user
     *
     * @param $userId
     * @param $from
     * @param $to
     * @param null $projectId
     * @return array
     * @throws Exception
     */
    public function getEntriesForUser($userId, $from, $to, $projectId = NULL)
    {
        $url = sprintf('%s/people/%s/entries?from=%s&to=%s',
            $this->getApiUrl(),
            $userId,
            $from,
            $to
        );
        if (!is_null($projectId)) {
            $url .= sprintf('&project_id=%s',
                $projectId
            );
        }
        $result = $this->doGetRequest($url);

        $xml = simplexml_load_string($result);
        if ($xml === false) {
            throw new Exception('Error while parsing xml.');
        }

        $groupedByDate = array();

        foreach ($xml->children() as $dayEntry) {
            /* @var $dayEntry SimpleXMLElement */
            $dayEntry = (array)$dayEntry;
            unset($dayEntry['timer-started-at']);
            unset($dayEntry['created-at']);
            unset($dayEntry['updated-at']);
            unset($dayEntry['is-closed']);
            unset($dayEntry['adjustment-record']);

            $date = $dayEntry['spent-at'];
            $projectId = $dayEntry['project-id'];
            $notes = $dayEntry['notes'];

            if (isset($groupedByDate[$date][$projectId][$notes])) {
                throw new Exception('Found more than one entry with the same note for the same project on the same day in harvest');
            }
            $groupedByDate[$date][$projectId][$notes] = $dayEntry;
        }

        return $groupedByDate;
    }

    /**
     * Get all projects
     *
     * @return SimpleXMLElement
     */
    public function getAllProjects() {
        $cacheKey = 'harvest_projects';
        if (!$this->cache || ($res = $this->cache->getItem($cacheKey)) == false) {
            $url = sprintf('%s/projects', $this->getApiUrl());
            $res = $this->doGetRequest($url);
            $this->cache->setItem($cacheKey, $res);
        }
        return new SimpleXMLElement($res);
    }

    /**
     * Get all clients
     *
     * @return SimpleXMLElement
     */
    public function getAllClients() {
        $cacheKey = 'harvest_clients';
        if (!$this->cache || ($res = $this->cache->getItem($cacheKey)) == false) {
            $url = sprintf('%s/clients', $this->getApiUrl());
            $res = $this->doGetRequest($url);
            $this->cache->setItem($cacheKey, $res);
        }
        return new SimpleXMLElement($res);
    }

    /**
     * Get all users
     *
     * @return SimpleXMLElement
     */
    public function getAllUsers() {
        $cacheKey = 'harvest_users';
        if (!$this->cache || ($res = $this->cache->getItem($cacheKey)) == false) {
            $url = sprintf('%s/people', $this->getApiUrl());
            $res = $this->doGetRequest($url);
            $this->cache->setItem($cacheKey, $res);
        }
        return new SimpleXMLElement($res);
    }

    /**
     * Get user name by user id
     *
     * @param $userId
     * @return string
     */
    public function getUserName($userId) {
        static $cache = array();
        if (!isset($cache[$userId])) {
            $users = $this->getAllUsers();
            list($firstName) = $users->xpath('//id[text()="'.$userId.'"]/../first-name/text()');
            list($lastName) = $users->xpath('//id[text()="'.$userId.'"]/../last-name/text()');
            $cache[$userId] = $firstName . ' ' . $lastName;
        }
        return $cache[$userId];
    }

    /**
     * Get project name by project id
     *
     * @param $projectId
     * @return string
     */
    public function getProjectName($projectId) {
        $projects = $this->getAllProjects();
        list($projectName) = $projects->xpath('//id[text()="'.$projectId.'"]/../name/text()');
        return (string)$projectName;
    }

    /**
     * Check if a given project is billable
     *
     * @param $projectId
     * @return bool
     */
    public function isBillable($projectId) {
        $projects = $this->getAllProjects();
        list($result) = $projects->xpath('//id[text()="'.$projectId.'"]/../billable/text()');
        return (string)$result == 'true';
    }

    /**
     * Get client name by client id
     *
     * @param $clientId
     * @return string
     */
    public function getClientName($clientId) {
        $clients = $this->getAllClients();
        list($clientName) = $clients->xpath('//id[text()="'.$clientId.'"]/../name/text()');
        return (string)$clientName;
    }

    /**
     * Get entries for project
     *
     * @param $projectId
     * @param $from
     * @param $to
     * @param null $userId
     * @return mixed|string
     */
    public function getEntriesForProject($projectId, $from, $to, $userId = NULL)
    {
        $cacheKey = "harvest_getEntriesForProject_{$projectId}_{$from}_{$to}_{$userId}";
        if (!$this->cache || ($res = $this->cache->getItem($cacheKey)) == false) {
            $url = sprintf('%s/projects/%s/entries?from=%s&to=%s',
                $this->getApiUrl(),
                $projectId,
                $from,
                $to
            );
            if (!is_null($userId)) {
                $url .= sprintf('&user_id=%s',
                    $userId
                );
            }
            $res = $this->doGetRequest($url);
            $this->cache->setItem($cacheKey, $res);
        }
        return $res;
    }

    /**
     * Do GET request
     *
     * @param $url
     * @return mixed
     * @throws Exception
     */
    protected function doGetRequest($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_VERBOSE, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->getCurlHeaders());
        curl_setopt($ch, CURLOPT_USERAGENT, "AOE Harvest Timetracker");

        $result = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new Exception("Error: " . curl_error($ch));
        }

        curl_close($ch);
        return $result;
    }

    /**
     * Do POST request
     *
     * @param $url
     * @param $xml
     * @return mixed
     * @throws Exception
     */
    protected function doPostRequest($url, $xml)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_VERBOSE, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->getCurlHeaders());
        curl_setopt($ch, CURLOPT_USERAGENT, "AOE Harvest Timetracker");

        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);

        $result = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new Exception("Error: " . curl_error($ch));
        }

        curl_close($ch);
        return $result;
    }

    /**
     * Get headers
     *
     * @return array
     * @throws Exception
     */
    protected function getCurlHeaders()
    {
        if (empty($this->email)) {
            throw new Exception('No email configured');
        }
        if (empty($this->password)) {
            throw new Exception('No password configured');
        }
        return array(
            "Content-type: application/xml",
            "Accept: application/xml",
            "Authorization: Basic " . base64_encode($this->email . ':' . $this->password)
        );
    }

    /**
     * Get API url
     *
     * @throws Exception
     * @return string
     */
    public function getApiUrl()
    {
        if (empty($this->account)) {
            throw new Exception('No account configured');
        }
        return sprintf(self::API_URL, $this->account);
    }

}