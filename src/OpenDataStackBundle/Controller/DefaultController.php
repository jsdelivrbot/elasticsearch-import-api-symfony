<?php

namespace OpenDataStackBundle\Controller;

use Elasticsearch\ClientBuilder;
use Enqueue\Fs\FsConnectionFactory;
use Nelmio\ApiDocBundle\Annotation\ApiDoc;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Filesystem\Exception\IOException;

use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\JsonResponse;

use Symfony\Component\HttpFoundation\Request;

class DefaultController extends Controller
{

    /**
     * add Import Configuration
     * @Route("/import-configuration")
     * @Method("POST")
     * @ApiDoc(
     *   tags={"in-development"},
     *   description="Add a new Import to Elasticsearch-Importer",
     *   method="POST",
     *   section="Import Configurations",
     *   statusCodes={
     *       200="success",
     *       400="error",
     *   },
     * )
     */
    public function addImportConfigurationAction(Request $request)
    {
        $payloadJson = $request->getContent();

        // 1. Request & Data Validation

        if (!$payloadJson) {
            return $this->logJsonResonse(400, "empty config parameters");
        }

        $payload = json_decode($payloadJson);

        if (json_last_error() != JSON_ERROR_NONE) {
            return $this->logJsonResonse(400, json_last_error_msg());
        }

        if (!property_exists($payload, "id") || !property_exists($payload, "type") || !property_exists($payload, "config")) {
            return $this->logJsonResonse(400, "Missing keys");
        }

        $udid = $payload->id;
        $fs = $this->container->get('filesystem');

        if ($fs->exists("/tmp/configurations/{$udid}")) {
            return $this->logJsonResonse(400, "Import configuration exist already");
        }

        // 2. Persist import-configuration in the filesystem
        try {
            $fs->mkdir("/tmp/configurations/{$udid}");
        } catch (IOException $exception) {
            return $this->logJsonResonse(400, $exception->getMessage());
        }


        // 3. Add mapping template to Elasticsearch
        // 3.1. init Elasticsearch client
        $client = ClientBuilder::create()
            ->setHosts([$this->container->getParameter('elastic_server_host')])
            ->setSSLVerification(false)
            ->build();

        // 3.2. (re)Create Template mapping for indexes under this import configuration

        $templateName = 'dkan-' . $udid;
        $mappings = $payload->config->mappings;

        if ($client->indices()->existsTemplate(['name' => $templateName])) {
            $client->indices()->deleteTemplate(['name' => $templateName]);
        }

        $elasticsearch = $client->indices()->putTemplate([
            'name' => $templateName,
            'body' => [
                'index_patterns' => [$templateName . '-*'],
                'settings' => ['number_of_shards' => 1],
                'mappings' => $mappings
            ]
        ]);

        // Persist response to log file
        $date = new \DateTime('now');
        $timestamp = $date->format('Y-m-d H:i:s');
        $log = [
            "message" => "dataset {$udid} created at {$timestamp}",
            "created_at" => $timestamp,
            "elasticsearch" => $elasticsearch
        ];

        $logJson = json_encode($log);
        file_put_contents("/tmp/configurations/{$udid}/log.json", $logJson);
        file_put_contents("/tmp/configurations/{$udid}/config.json", $payloadJson);

        //TODO: call kibana to create an index pattern with 'Kibana Rest Api'

        // 4. Respond successfully for import configuration added
        return $this->logJsonResonse(200, $log['message']);
    }


    /**
     * update Import Configuration
     * @Route("/import-configuration")
     * @Method("PUT")
     * @ApiDoc(
     *   tags={"in-development"},
     *   description="Update an Import Configuration",
     *   method="PUT",
     *   section="Import Configurations",
     *   statusCodes={
     *       200="success",
     *       400="error",
     *   },
     * )
     */
    public function updateImportConfigurationAction(Request $request)
    {

        //TODO:

    }


    /**
     * status Resource
     * @Route("/import-configuration/{udid}/resource/{resourceId}")
     * @Method("GET")
     * @ApiDoc(
     *   tags={"in-development"},
     *   description="Status for a csv resource",
     *   method="GET",
     *   requirements={
     *    {
     *      "name"="udid",
     *      "dataType"="string",
     *      "description"="udid of the dataset"
     *    },
     *     {
     *      "name"="resourceId",
     *      "dataType"="string",
     *      "description"="udid of the resource"
     *    }
     *   },
     *   section="Import Configurations",
     *   statusCodes={
     *       200="success",
     *       404="not found",
     *       400="error",
     *   },
     * )
     */
    public function statusResourceAction($udid, $resourceId)
    {

        if (!file_exists("/tmp/configurations/{$udid}")) {
            return $this->logJsonResonse(404, "no configuration with the udid: {$udid}");
        }

        if (!file_exists("/tmp/configurations/{$udid}/{$resourceId}")) {
            return $this->logJsonResonse(404, "no resource with the udid: {$resourceId}");
        }

        // parse log file and return the persisted status
        $logJson = file_get_contents("/tmp/configurations/{$udid}/{$resourceId}/log.json");
        $log = json_decode($logJson);

        return $this->logJsonResonse(200, $log->message, ["flag" => $log->status]);
    }



    /**
     * status Configuration
     * @Route("/import-configuration/{udid}/resources")
     * @Method("GET")
     * @ApiDoc(
     *   description="Returns list resources for an Import configuration",
     *   tags={"in-development"},
     *   method="GET",
     *   requirements={
     *    {
     *      "name"="udid",
     *      "dataType"="string",
     *      "description"="udid of the dataset"
     *    }
     *   },
     *   section="Import Configurations",
     *   statusCodes={
     *       200="success",
     *       400="error",
     *       404="not found",
     *   }
     *
     * )
     */
    public function statusResourcesListAction($udid)
    {

        // Get list of folders for the import configurations
        $finder = new Finder();
        $folders = $finder->directories()->in("/tmp/configurations/{$udid}");

        $listResources = [];
        foreach ($folders as $folder) {
            $listResources[] = basename($folder);
        }

        // Response with list of saved import configurations
        $status = 0;
        $message = "";
        if ($listResources) {
            $status = 200;
            $message = vsprintf("%d resource(s) found", count($listResources));
        } else {
            $status = 404;
            $message = "No result";
        }
        $status = ($listResources) ? 200 : 404;
        return $this->logJsonResonse($status, $message, ["ids" => $listResources]);

    }

    /**
     * status Configuration
     * @Route("/import-configuration/{udid}")
     * @Method("GET")
     * @ApiDoc(
     *   description="Returns a status for an Import configuration",
     *   tags={"in-development"},
     *   method="GET",
     *   requirements={
     *    {
     *      "name"="udid",
     *      "dataType"="string",
     *      "description"="udid of the resource"
     *    }
     *   },
     *   section="Import Configurations",
     *   statusCodes={
     *       200="success",
     *       400="error",
     *       404="not found",
     *   }
     *
     * )
     */
    public function statusConfigurationAction($udid)
    {

        if (!file_exists("/tmp/configurations/{$udid}")) {
            return $this->logJsonResonse(404, "no configuration with the udid: {$udid}");
        }

        // parse log file and return the persisted status
        $logJson = file_get_contents("/tmp/configurations/{$udid}/log.json");
        $log = json_decode($logJson);

        return $this->logJsonResonse(200, $log->message);
    }

    /**
     * status Configurations List
     * @Route("/import-configurations")
     * @Method("GET")
     * @ApiDoc(
     *   description="Returns a list of Import configurations",
     *   tags={"in-development"},
     *   method="GET",
     *   section="Import Configurations",
     *   statusCodes={
     *       200="success",
     *       400="error",
     *       404="not found",
     *   }
     *
     * )
     */
    public function statusConfigurationsListAction()
    {

        // Get list of folders for the import configurations
        $finder = new Finder();
        $folders = $finder->directories()->in("/tmp/configurations");

        $listImportConfigurations = [];
        foreach ($folders as $folder) {
            $listImportConfigurations[] = basename($folder);
        }

        // Response with list of saved import configurations
        $status = 0;
        $message = "";
        if ($listImportConfigurations) {
            $status = 200;
            $message = vsprintf("%d import configurations found", count($listImportConfigurations));
        } else {
            $status = 404;
            $message = "No result";
        }
        $status = ($listImportConfigurations) ? 200 : 404;
        return $this->logJsonResonse($status, $message, ["ids" => $listImportConfigurations]);
    }

    /**
     * delete Configuration
     * @Route("/import-configuration/{udid}")
     * @Method("DELETE")
     * @ApiDoc(
     *   description="Delete an Import configuration",
     *   tags={"in-development"},
     *   method="DELETE",
     *   requirements={
     *    {
     *      "name"="udid",
     *      "dataType"="string",
     *      "description"="udid of the resource to be deleted"
     *    }
     *   },
     *   section="Import Configurations",
     *   statusCodes={
     *       200="success",
     *       400="error",
     *       404="not found",
     *   }
     *
     * )
     */
    public function deleteConfigurationAction($udid)
    {

        // validate UDID exist
        if (!$udid) {
            return $this->logJsonResonse(400, "udid parameters required");
        }

        if (!file_exists("/tmp/configurations/{$udid}")) {
            return $this->logJsonResonse(404, "no configuration with the udid: {$udid}");
        }

        // Remove the import configurations folder
        $fs = $this->container->get('filesystem');

        try {
            $fs->remove("/tmp/configurations/{$udid}");
        } catch (IOException $exception) {
            return $this->logJsonResonse(400, "IOException on delete {$udid}");
        }

        return $this->logJsonResonse(200, "{$udid} deleted");
    }

    /**
     * request ImportConfiguration
     * @Route("/request-import")
     * @Method("POST")
     * @ApiDoc(
     *   description="Request a resource fetch",
     *   tags={"in-development"},
     *   method="PUT",
     *   section="Import Configurations",
     *   statusCodes={
     *       200="success",
     *       400="error",
     *       404="not found",
     *   }
     *
     * )
     */
    public function requestImportConfigurationAction(Request $request)
    {

        // 1. Validate json payload content

        $payloadJson = $request->getContent();

        if (!$payloadJson) {
            return $this->logJsonResonse(400, "empty config parameters");
        }

        $payload = json_decode($payloadJson);

        if (json_last_error() != JSON_ERROR_NONE) {
            return $this->logJsonResonse(400, json_last_error_msg());
        }

        if (!property_exists($payload, "id") || !property_exists($payload, "type")
            || !property_exists($payload, "url") || !property_exists($payload, "udid")) {
            return $this->logJsonResonse(400, "Missing keys");
        }

        $udid = $payload->udid;
        $resourceId = $payload->id;

        if (!file_exists("/tmp/configurations/{$udid}")) {
            return $this->logJsonResonse(404, "no configuration with the udid: {$udid}");
        }

        // 2. Update import config status to : ** queued **

        $date = new \DateTime('now');
        $timestamp = $date->format('Y-m-d H:i:s');
        $log = [
            "message" => "resource {$resourceId} created at {$timestamp}",
            "created_at" => $timestamp,
            "status" => "queued"
        ];

        $logJson = json_encode($log);
        file_put_contents("/tmp/configurations/{$udid}/{$resourceId}/log.json", $logJson);


        // 3. Produce a message to process in the queue

        $connectionFactory = new FsConnectionFactory('/tmp/enqueue');
        $context = $connectionFactory->createContext();

        $data = [
            'importer'  => $payload->type,
            'uri'       => $payload->url,
            'udid'      => $payload->udid,
            'id'        => $payload->id
        ];
        $queue = $context->createQueue('importQueue');
        $context->createProducer()->send(
            $queue,
            $context->createMessage(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
        );

        return $this->logJsonResonse(200, "Resource {$resourceId} for import configuration {$udid} is queued");
    }

    /**
     * A Json Response helpers
     *
     * @param $status 200:success , 40* for failed
     * @param $message
     * @param array|NULL $info
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    private function logJsonResonse($status, $message, array $info = null)
    {
        $response = new JsonResponse(
            [
                "log" => [
                    "status" => ($status == 200 ? "success" : "fail"),
                    "message" => $message,
                    "info" => $info
                ]
            ],
            $status
        );
        return $response;
    }
}
