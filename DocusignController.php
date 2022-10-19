<?php

namespace App\Http\Controllers;

use App\Lead;
use App\Services\DocusignService;
use App\Http\Controllers\Controller;
use DocuSign\eSign\Client\ApiException;
use Illuminate\Support\Facades\Storage;

class DocusignController extends Controller
{

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function addAccount()
    {
        $docusign = new DocusignService();
        $docusign->createAccount(request('email'));
    }

    /**
     * @return \DocuSign\eSign\Model\EnvelopeTemplate[]
     */
    public function listTemplates()
    {
        $docusign = new DocusignService();

        try {
            $templates = $docusign->templates_api->listTemplates($docusign->account_id);
        } catch (ApiException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'success' => false,
            ], 500);
        }
        return $templates;
    }

    /**
     * @param String $template_id
     * 
     * @return \DocuSign\eSign\Model\EnvelopeTemplate
     */
    public function getTemplate(String $template_id)
    {
        $docusign = new DocusignService();
        return $docusign->templates_api->get($docusign->account_id, $template_id);
    }

    /**
     * @param String $template_id
     * 
     * @return Array
     */
    public function getTabs($template_id)
    {
        $docusign = new DocusignService();
        $template = $docusign->templates_api->get($docusign->account_id, $template_id);
        $tabs = $docusign->templates_api->listTabs($docusign->account_id, $template->getRecipients()->getSigners()[0]->getRecipientId(), $template_id);
        return response()->json([
            'tabs' => json_decode($tabs, null, 5),
            'recipients' => json_decode($template->getRecipients(), null, 5)
        ]);
    }

    /**
     * Docusign Event Callback
     * 
     */
    public static function callback()
    {
        $data = file_get_contents('php://input');
        $xml = simplexml_load_string($data, "SimpleXMLElement", LIBXML_PARSEHUGE);
        $time_generated = (string) $xml->EnvelopeStatus->TimeGenerated;
        $envelope_id = (string) $xml->EnvelopeStatus->EnvelopeID;

        $envelope_dir = '/envelopes/' . $envelope_id;
        $filename = $envelope_dir . "/webhooks/" .
            str_replace(':', '_', $time_generated) . ".xml"; // substitute _ for : for windows-land

        if ($envelope_id) {
            $ok = Storage::disk('s3')->put($filename, $data);
            if ($ok === false) {
                // Couldn't write the file! Alert the humans!
                logger("!!!!!! PROBLEM DocuSign Webhook: Couldn't store $filename !");
                exit(1);
            }
        }

        // log the event

        if ((string) $xml->EnvelopeStatus->Status === "Completed" || (string) $xml->EnvelopeStatus->Status === "Signed") {
            $lead = Lead::where(['envelope_id' => $envelope_id])->first();
            if($lead){
                $lead->getDocsFromDocusign();
            }
        }
    }

    /**
     * Redirect to the Docusign OAUTH consent screen
     * @return \Illuminate\Http\RedirectResponse
     */
    public function getOauthUrl()
    {
        $docusign = new DocusignService();
        return redirect($docusign->getOauthUrl());
    }

    /** */
    public function handleOauthCallback()
    {

        $docusign = new DocusignService();
        return $docusign->getTokenFromCode(request('code'));
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function revoke()
    {
        setting()->forget('docusign_access_token');
        setting()->forget('docusign_refresh_token');
        setting()->forget('docusign_expires_at');
        setting()->save();
        return response()->json([
            'message' => 'Docusign has been disconnected!',
            'success' => true,
        ], 200);
    }
}
