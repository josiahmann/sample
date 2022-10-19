<?php

namespace App\Services;

use App\Traits\AuthorizesDocusign;

class DocusignService
{
    use AuthorizesDocusign;

    private $rest_api_path = 'https://na4.docusign.net/restapi';
    private $base_path = 'https://account.docusign.com';
    private $token, $integrator_key, $secret;
    public $account_id, $client;
    /** @var \DocuSign\eSign\Api\AccountsApi $accounts_api */
    public $accounts_api;
    /** @var \DocuSign\eSign\Api\TemplatesApi $templates_api */
    public $templates_api;
    /** @var \DocuSign\eSign\Api\EnvelopesApi $envelopes_api */
    public $envelopes_api;

    public $event_notification, $webhook_url, $oauth_url;

    public function __construct()
    {
        if (config('app.env') == 'local') {
            $this->rest_api_path = 'https://demo.docusign.net/restapi';
            $this->base_path = 'https://account-d.docusign.com';
        }

        $this->account_id = config('services.docusign.account_id');
        $this->integrator_key = config('services.docusign.integrator_key');
        $this->secret = config('services.docusign.secret');
        $this->token = $this->getToken();
        $this->webhook_url = route('docusign_callback');

        $this->init();
    }

    public function init()
    {
        if (!setting()->get('docusign_access_token')) {
            return;
        }
        $config = new \DocuSign\eSign\Configuration();
        $config->setHost($this->rest_api_path);

        if ($this->getToken()) {
            $config->addDefaultHeader("Authorization", "Bearer " . $this->getToken());
        }

        $this->client = new \DocuSign\eSign\Client\ApiClient($config);
        $this->accounts_api = new \DocuSign\eSign\Api\AccountsApi($this->client);
        $this->templates_api = new \DocuSign\eSign\Api\TemplatesApi($this->client);
        $this->envelopes_api = new \DocuSign\eSign\Api\EnvelopesApi($this->client);
        $this->setEventNotifications();
    }

    public function createAccount()
    {
        $new_account_definition = new \DocuSign\eSign\Model\NewAccountDefinition();
        $new_account_definition->setAccountName(request()->user()->getFullName());
        $user_info = new \DocuSign\eSign\Model\UserInformation();
        $user_info->setEmail(request()->user()->email);
        $user_info->setFirstName(request()->user()->first_name);
        $user_info->setLastName(request()->user()->last_name);
        $new_account_definition->setInitialUser($user_info);

        $this->accounts_api->create($new_account_definition);
    }


    public function getEnvelopeEvents()
    {
        return [
            (new \DocuSign\eSign\Model\EnvelopeEvent())->setEnvelopeEventStatusCode("sent"),
            (new \DocuSign\eSign\Model\EnvelopeEvent())->setEnvelopeEventStatusCode("delivered"),
            (new \DocuSign\eSign\Model\EnvelopeEvent())->setEnvelopeEventStatusCode("completed"),
            (new \DocuSign\eSign\Model\EnvelopeEvent())->setEnvelopeEventStatusCode("declined"),
            (new \DocuSign\eSign\Model\EnvelopeEvent())->setEnvelopeEventStatusCode("voided"),
            (new \DocuSign\eSign\Model\EnvelopeEvent())->setEnvelopeEventStatusCode("sent")
        ];
    }

    public function getRecipientEvents()
    {
        return [
            (new \DocuSign\eSign\Model\RecipientEvent())->setRecipientEventStatusCode("Sent"),
            (new \DocuSign\eSign\Model\RecipientEvent())->setRecipientEventStatusCode("Delivered"),
            (new \DocuSign\eSign\Model\RecipientEvent())->setRecipientEventStatusCode("Completed"),
            (new \DocuSign\eSign\Model\RecipientEvent())->setRecipientEventStatusCode("Declined"),
        ];
    }

    public function setEventNotifications()
    {
        $event_notification = new \DocuSign\eSign\Model\EventNotification();
        $event_notification->setUrl($this->webhook_url);
        $event_notification->setLoggingEnabled("true");
        $event_notification->setRequireAcknowledgment("true");
        $event_notification->setIncludeDocuments("true");
        $event_notification->setIncludeEnvelopeVoidReason("true");
        $event_notification->setIncludeDocumentFields("true");
        $event_notification->setIncludeCertificateOfCompletion("true");
        $event_notification->setEnvelopeEvents($this->getEnvelopeEvents());
        $event_notification->setRecipientEvents($this->getRecipientEvents());
        $this->event_notification = $event_notification;
    }


    /**
     * @param String $signature_template_id
     * @param \DocuSign\eSign\Model\Signer[] $signers
     * @return \DocuSign\eSign\Model\EnvelopeSummary $envelope_summary
     */
    public function requestSignatureFromTemplate(String $signature_template_id, $signers)
    {
        # Create the envelope definition with the template_id
        $envelope_definition = new \DocuSign\eSign\Model\EnvelopeDefinition([
            'status' => 'sent',
            'template_id' => $signature_template_id
        ]);
        $envelope_definition->setEventNotification($this->event_notification);

        # Add the TemplateRole objects to the envelope object
        $envelope_definition->setTemplateRoles($signers);

        $envelope_summary = $this->envelopes_api->createEnvelope($this->account_id, $envelope_definition);

        return $envelope_summary;
    }

    /**
     * @param String $signature_template_id
     * @param \DocuSign\eSign\Model\Signer[] $signers
     */
    public function requestEmbeddedSignatureFromTemplate(String $signature_template_id, $signers, $redirect_url)
    {
        $redirect_url = $redirect_url ? $redirect_url : config('app.url');
        $envelope_summary = $this->requestSignatureFromTemplate($signature_template_id, $signers);

        /**********************************************************************************************
         * 2. Get embedded view
         *******************************************************************************************/
        $view_request = new \DocuSign\eSign\Model\RecipientViewRequest(
            [
                'return_url' => $redirect_url,
                'authentication_method' => 'email',
                'client_user_id' => $signers[0]->getClientUserId(),
                'recipient_id' => $signers[0]->getRecipientId(),
                'email' => $signers[0]->getEmail(),
                'user_name' => $signers[0]->getName()
            ]
        );
        $results = $this->envelopes_api->createRecipientView($this->account_id, $envelope_summary->getEnvelopeId(), $view_request);

        $url = $results['url'];

        return response()->redirectTo($url);
    }


    public function listRequests()
    {
        $options = new \DocuSign\eSign\Api\EnvelopesApi\ListStatusChangesOptions();
        $options->setFromDate(now());
        return $this->envelopes_api->listStatusChanges($this->account_id, $options)->getEnvelopes();
    }
}
