<?php
namespace verbb\formie\integrations\payments;

use verbb\formie\Formie;
use verbb\formie\base\FormField;
use verbb\formie\base\Integration;
use verbb\formie\base\Payment;
use verbb\formie\elements\Submission;
use verbb\formie\events\ModifyPaymentCurrencyOptionsEvent;
use verbb\formie\events\ModifyPaymentPayloadEvent;
use verbb\formie\events\PaymentReceiveWebhookEvent;
use verbb\formie\fields\formfields;
use verbb\formie\helpers\ArrayHelper;
use verbb\formie\helpers\SchemaHelper;
use verbb\formie\helpers\Variables;
use verbb\formie\models\IntegrationField;
use verbb\formie\models\Payment as PaymentModel;
use verbb\formie\models\Plan;

use Craft;
use craft\helpers\App;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\Response;

use yii\base\Event;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Exception\ClientException as GuzzleClientException;

use Throwable;
use Exception;

class QuickStream extends Payment
{
    // Constants
    // =========================================================================

    public const EVENT_MODIFY_PAYLOAD = 'modifyPayload';


    // Static Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    public static function displayName(): string
    {
        return Craft::t('formie', 'Westpac QuickStream');
    }

    // Properties
    // =========================================================================

    public ?string $publishableKey = null;
    public ?string $supplierBusinessCode = null;
    public ?string $secretKey = null;
    public ?bool $isTestGateway = true;
    // public ?string $merchantId = null;


    // Public Methods
    // =========================================================================

    public function getDescription(): string
    {
        return Craft::t('formie', 'Provide payment capabilities for your forms with Westpac QuickStream.');
    }

    /**
     * @inheritDoc
     */
    public function hasValidSettings(): bool
    {
        return App::parseEnv($this->publishableKey) && App::parseEnv($this->secretKey) && App::parseEnv($this->supplierBusinessCode);
    }

    /**
     * @inheritDoc
     */
    public function getFrontEndHtml($field, $renderOptions): string
    {
        if (!$this->hasValidSettings()) {
            return '';
        }

        $this->setField($field);

        return Craft::$app->getView()->renderTemplate('formie/integrations/payments/quickstream/_input', [
            'field' => $field,
            'renderOptions' => $renderOptions,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getFrontEndJsVariables($field = null): ?array
    {
        if (!$this->hasValidSettings()) {
            return null;
        }

        $this->setField($field);

        $isTestGateway = (null == $this->isTestGateway || false == $this->isTestGateway)? false : true;

        $settings = [
            'publishableKey' => App::parseEnv($this->publishableKey),
            'supplierBusinessCode' => App::parseEnv($this->supplierBusinessCode),
            'threeDS2Enabled' => $this->getFieldSetting('threeDS2Enabled') ?? false,
            'currency' => $this->getFieldSetting('currency'),
            'amountType' => $this->getFieldSetting('amountType'),
            'amountFixed' => $this->getFieldSetting('amountFixed'),
            'amountVariable' => $this->getFieldSetting('amountVariable'),
            'isTestGateway' => $isTestGateway,
            'showReference' => $this->getFieldSetting('showReference'),
            'referenceField' => $this->getFieldSetting('referenceField'),
        ];

        return [
            'src' => Craft::$app->getAssetManager()->getPublishedUrl('@verbb/formie/web/assets/frontend/dist/js/payments/quickstream.js', true),
            'module' => 'FormieQuickStream',
            'settings' => $settings,
        ];
    }

    /**
     * @inheritDoc
     */
    public function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['clientId', 'clientSecret'], 'required', 'on' => [Integration::SCENARIO_FORM]];

        return $rules;
    }
    
    /**
     * @inheritDoc
     */
    public function processPayment(Submission $submission): bool
    {
        $response = null;
        $result = false;
        $businessCode = $this->getFieldSetting('supplierBusinessCodeOverride');
        $businessCode = ("" !== $businessCode && null !== $businessCode)? $businessCode : App::parseEnv($this->supplierBusinessCode);

        // Allow events to cancel sending
        if (!$this->beforeProcessPayment($submission)) {
            return true;
        }

        // Get the amount from the field, which handles dynamic fields
        $amount = $this->getAmount($submission);
        $currency = $this->getFieldSetting('currency');

        // Capture the authorized payment
        try {
            $field = $this->getField();
            $fieldValue = $submission->getFieldValue($field->handle);
            $quickstreamTokenId = $fieldValue['quickstreamTokenId'] ?? null;

            if (!$quickstreamTokenId || !is_string($quickstreamTokenId)) {
                throw new Exception("Missing `quickstreamTokenId` from payload: {$quickstreamTokenId}.");
            }

            if (!$amount) {
                throw new Exception("Missing `amount` from payload: {$amount}.");
            }

            if (!$currency) {
                throw new Exception("Missing `currency` from payload: {$currency}.");
            }

            // QuickStream doesn't like local IP's, so we'll reference Simple's static IP when testing in a dev environment:
            $payload = [
                'transactionType' => 'PAYMENT',
                'singleUseTokenId' => $quickstreamTokenId,
                'supplierBusinessCode' => $businessCode,
                'principalAmount' => $amount,
                'currency' => 'AUD',
                'metadata' => [
                    'submissionId' => (string) $submission->id,
                ],
                'eci' => 'INTERNET',
                'ipAddress' => (env('ENVIRONMENT') == 'dev')? '103.142.159.66' : Craft::$app->getRequest()->getUserIP(),
                'threeDS2' => (bool) $this->getFieldSetting('threeDS2Enabled') ?? false,
            ];

            // Optional customer reference number added to payment if defined in settings:
            if ($this->getFieldSetting('showReference') && null !== $this->getFieldSetting('referenceField')) {
                // extract the value from the curly-bracket-wrapped string i.e. "{crnNumber}"
                $referenceFieldHandle =
                    StringHelper::removeRight(
                        StringHelper::removeLeft(
                            $this->getFieldSetting('referenceField'),
                    '{'), '}');

                if (null !== $submission->getFieldValue($referenceFieldHandle)) {
                    $payload['customerReferenceNumber'] = $submission->getFieldValue($referenceFieldHandle);
                }
            }

            // Raise a `modifySinglePayload` event
            $event = new ModifyPaymentPayloadEvent([
                'integration' => $this,
                'submission' => $submission,
                'payload' => $payload,
            ]);
            $this->trigger(self::EVENT_MODIFY_PAYLOAD, $event);

            $response = $this->request('POST', 'transactions', [
                'headers' => [
                    // 'User-Agent' => 'testing/1.0',
                    'Content-Type' => 'application/json',
                    'Accept'     => 'application/json',
                ],
                'json' => $event->payload
            ]);

            $status = $response['status'] ?? null;
            $responseText = $response['responseText'] ?? null;
            $customerMsg = $response['customerMessage'] ?? null;

            if ($status !== 'Approved' && $status !== 'Approved*' && $status !== 'Pending') {
                throw new Exception(StringHelper::titleize($status) . ': ' . $responseText);
            }

            $payment = new PaymentModel();
            $payment->integrationId = $this->id;
            $payment->submissionId = $submission->id;
            $payment->fieldId = $field->id;
            $payment->amount = $amount;
            $payment->currency = $currency;
            $payment->reference = $response['receiptNumber'] ?? '';
            $payment->response = $response;

            if ($status === 'Pending') {
                $payment->status = PaymentModel::STATUS_PENDING;
            }

            if ($status === 'Approved' || $status === 'Approved*') {
                $payment->status = PaymentModel::STATUS_SUCCESS;
            }

            Formie::$plugin->getPayments()->savePayment($payment);

            $result = true;
        } catch (Throwable $e) {
            // Save a different payload to logs
            Integration::error($this, Craft::t('formie', 'Payment error: “{message}” {file}:{line}. Response: “{response}”', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'response' => Json::encode($response),
            ]));

            Integration::apiError($this, $e, $this->throwApiError);

            $reason = "";
            if (null !== $e->getMessage())
                $reason .= $e->getMessage().' ';

            if (isset($response['responseCode']))
                $reason .= 'Response Code '.$response['responseCode'].' ';

            if (isset($response['responseDescription']))
                $reason .= '('.$response['responseDescription'].')';

            if (isset($response['fraudGuardResult']) && $response['fraudGuardResult'] !== "" && strtolower($response['fraudGuardResult']) !== "ok")
                $reason .= ' - '.$response['fraudGuardResult'];

            $submission->addError($field->handle, Craft::t('formie', 'A payment error occured - ' . $reason ));
            
            $payment = new PaymentModel();
            $payment->integrationId = $this->id;
            $payment->submissionId = $submission->id;
            $payment->fieldId = $field->id;
            $payment->amount = $amount;
            $payment->currency = $currency;
            $payment->status = PaymentModel::STATUS_FAILED;
            $payment->reference = null;
            $payment->response = $customerMsg ?? ['message' => $e->getMessage()];

            Formie::$plugin->getPayments()->savePayment($payment);

            return false;
        }

        // Allow events to say the response is invalid
        if (!$this->afterProcessPayment($submission, $result)) {
            return true;
        }

        return $result;
    }
    
    /**
     * Triggered with JS, via the '/actions/formie/integrations/quickstream/request-3d-secure-auth' endpoint,
     * via the 'actionRequest3dSecureAuth' method in src/controllers/integrations/QuickstreamController.php
     * 
     * @param string $tokenId The single-use token ID
     * @param array $params The params from the request, for 3D Secure to check
     * @param array $liveMode Whether to process the reqwuest using the live gateway or not
     */
    public function request3DSecureAuth($tokenId, $params, $liveMode): Response
    {
        $response = new Response();
        $response->format = Response::FORMAT_JSON;

        // grab all req'd env's and ensure they are set:
        $publishableKey = App::env('QUICKSTREAM_PUBLISHABLE_KEY');
        $secretKey = App::env('QUICKSTREAM_SECRET_KEY');
        $supplierBusinessCode = App::env('QUICKSTREAM_SUPPLIER_BUSINESS_CODE');

        // grab the isTestGateway prop from the integration settings, and pass the baseUri accordingly (as this seems to fail when relying)
        $baseUri = ($liveMode)? 'https://api.quickstream.westpac.com.au/rest/v1/' : 'https://api.quickstream.support.qvalent.com/rest/v1/';

        if (!$publishableKey || !$secretKey || !$supplierBusinessCode) {
            $response->statusCode = 500;
            $response->data = [
                'threeDsStatus' => null,
                'success' => false,
                'message' => 'Missing environment variables for 3D Secure',
            ];

            return $response;
        }

        if (!isset($params['principalAmount']) || !isset($params['email']) || !isset($params['acctID'])) {
            $missing = array_merge([], (!isset($params['principalAmount'])) ? ['principalAmount'] : []);
            $missing = array_merge($missing, (!isset($params['email'])) ? ['email'] : []);
            $missing = array_merge($missing, (!isset($params['acctID'])) ? ['acctID'] : []);

            $response->statusCode = 400;
            $response->data = [
                'threeDsStatus' => null,
                'success' => false,
                'message' => 'Missing '. implode(", ",$missing) .' for 3D Secure',
            ];

            return $response;
        }

        // send the params to "/single-use-tokens/{singleUseTokenId}/three-ds2-authentication" for 3DS validation
        // https://api.quickstream.westpac.com.au/rest/v1/single-use-tokens/{singleUseTokenId}/three-ds2-authentication

        // If successful, this method returns a 3D Secure Authentication Response Model in the response body.
        try {
            $threeDsResponse = $this->request('POST', 'single-use-tokens/' . $tokenId . '/three-ds2-authentication', [
                'base_uri' => $baseUri,
                'auth' => [$secretKey, ''],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept'     => 'application/json',
                ],
                'json' => [
                    'messageCategory' => '01',
                    'threeDSRequestorAuthenticationInd' => '01',
                    'currency' => 'AUD',
                    'principalAmount' => $params['principalAmount'],
                    'email' => $params['email'],
                    'acctID' => $params['acctID'],
                ]
            ]);

            // get the 'transStatus' from the response body and switch on it:
            switch (($threeDsResponse['transStatus'] ?? null)) {
                case 'A':
                case 'Y':
                    // 3DS Frictionless Flow
                    $response->data['threeDsStatus'] = "frictionless";
                    $response->data['success'] = true;
                    $response->data['message'] = "3D Secure Authentication successful";
                    $response->statusCode = 200;
                    break;
                case 'C':
                    // 3DS Challenge Flow
                    $response->data['threeDsStatus'] = "challenge";
                    $response->data['success'] = false;
                    $response->data['message'] = "3D Secure Authentication additional challenge required";
                    $response->statusCode = 200;
                    break;
                case 'N':
                case 'R':
                case 'U':
                    // 3DS Authentication Failed
                    $response->data['threeDsStatus'] = "failed";
                    $response->data['success'] = false;
                    $response->data['message'] = "3D Secure Authentication Failed - Your payment could not be processed. Please review and try again.";
                    $response->statusCode = 400;
                    break;
                default:
                    // 3DS Authentication error
                    $response->data['threeDsStatus'] = "error";
                    $response->data['success'] = false;
                    $response->data['message'] = "3D Secure Authentication Failed - Your payment could not be processed. Please review and try again.";
                    $response->statusCode = 500;
                    break;
            }

        } catch (GuzzleRequestException | GuzzleClientException $e) {
            $response->data['threeDsStatus'] = "error";
            $response->data['success'] = false;
            $response->data['message'] = "A request error occured" . $e->getMessage();
            $response->statusCode = $e->getCode();
        } catch (Exception $e) {
            $response->data['threeDsStatus'] = "error";
            $response->data['success'] = false;
            $response->data['message'] = $e->getMessage();
            $response->statusCode = 500;
        }

        return $response;
    }

    /**
     * @inheritDoc
     */
    public function fetchConnection(): bool
    {
        try {
            $response = $this->request('GET', '/');
        } catch (Throwable $e) {
            Integration::apiError($this, $e);

            return false;
        }

        return true;
    }

    public function getClient(): Client
    {
        if ($this->_client) {
            return $this->_client;
        }

        /* QUICKSTREAM API ENDPOINTS */
        // Prod:    'https://api.quickstream.westpac.com.au/rest/v1/'
        // Staging: 'https://api.quickstream.support.qvalent.com/rest/v1/'
        $isTestGateway = (null == $this->isTestGateway || false == $this->isTestGateway)? false : true;

        return $this->_client = Craft::createGuzzleClient([
            'base_uri' => ($isTestGateway == false)? 'https://api.quickstream.westpac.com.au/rest/v1/' : 'https://api.quickstream.support.qvalent.com/rest/v1/',
            'auth' => [App::parseEnv($this->secretKey), ''],
        ]);
    }

    /**
     * @inheritDoc
     */
    public function defineGeneralSchema(): array
    {
        return [
            SchemaHelper::textField([
                'label' => Craft::t('formie', '(optional) Supplier Business Code'),
                'help' => Craft::t('formie', 'If you would like this form to link to a different Supplier Business Code than this gateway\'s default, input the code here.'),
                'name' => 'supplierBusinessCodeOverride',
                'validation' => 'alphanumeric',
            ]),
            SchemaHelper::lightswitchField([
                'label' => Craft::t('formie', 'Enable 3D Secure?'),
                'help' => Craft::t('formie', 'Should this form use 3D Secure?'),
                'name' => 'threeDS2Enabled',
            ]),
            SchemaHelper::selectField([
                'label' => Craft::t('formie', 'Payment Currency'),
                'help' => Craft::t('formie', 'Provide the currency to be used for the transaction.'),
                'name' => 'currency',
                'required' => true,
                'validation' => 'required',
                'options' => array_merge(
                    [['label' => Craft::t('formie', 'Select an option'), 'value' => '']],
                    static::getCurrencyOptions()
                ),
            ]),
            [
                '$formkit' => 'fieldWrap',
                'label' => Craft::t('formie', 'Payment Amount'),
                'help' => Craft::t('formie', 'Provide an amount for the transaction. This can be either a fixed value, or derived from a field.'),
                'children' => [
                    [
                        '$el' => 'div',
                        'attrs' => [
                            'class' => 'flex',
                        ],
                        'children' => [
                            SchemaHelper::selectField([
                                'name' => 'amountType',
                                'options' => [
                                    ['label' => Craft::t('formie', 'Fixed Value'), 'value' => Payment::VALUE_TYPE_FIXED],
                                    ['label' => Craft::t('formie', 'Dynamic Value'), 'value' => Payment::VALUE_TYPE_DYNAMIC],
                                ],
                            ]),
                            SchemaHelper::numberField([
                                'name' => 'amountFixed',
                                'size' => 6,
                                'if' => '$get(amountType).value == ' . Payment::VALUE_TYPE_FIXED,
                            ]),
                            SchemaHelper::fieldSelectField([
                                'name' => 'amountVariable',
                                'fieldTypes' => [
                                    formfields\Calculations::class,
                                    formfields\Dropdown::class,
                                    formfields\Hidden::class,
                                    formfields\Number::class,
                                    formfields\Radio::class,
                                    formfields\SingleLineText::class,
                                ],
                                'if' => '$get(amountType).value == ' . Payment::VALUE_TYPE_DYNAMIC,
                            ]),
                        ],
                    ],
                ],
            ],
            [
                '$formkit' => 'fieldWrap',
                'label' => Craft::t('formie', '(optional) Payment Reference Field'),
                // 'help' => Craft::t('formie', 'Allow customers to input a payment reference. This can be derived from a field.'),
                'help' => Craft::t('formie', 'Allow customers to input a payment reference by defining a field in the form.'),
                'children' => [
                    [
                        '$el' => 'div',
                        'attrs' => [
                            'class' => 'flex',
                        ],
                        'children' => [
                            SchemaHelper::fieldSelectField([
                                'name' => 'referenceField',
                                'help' => Craft::t('formie', 'Please ensure you have added a single-line text field to the form, to reference here. Leave blank to not use this feature.'),
                                'fieldTypes' => [
                                    formfields\SingleLineText::class,
                                ],
                            ]),
                        ],
                    ],
                ],
            ],
        ];
    }
    

    // Protected Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    protected function getIntegrationHandle(): string
    {
        return 'quickstream';
    }
}