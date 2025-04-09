import { t, eventKey, ensureVariable } from '../utils/utils';
import { FormiePaymentProvider } from './payment-provider';

export class FormieQuickStream extends FormiePaymentProvider {
    constructor(settings = {}) {
        super(settings);

        this.boundEvents = false;
        this.trustedFrame = false;
        this.$form = settings.$form;
        this.form = this.$form.form;
        this.$field = settings.$field;
        this.$input = this.$field.querySelector('[data-fui-quickstream-frame]');
        this.$submitButton = this.form.$form.querySelector('button[type="submit"]');

        this.$csrfToken = this.$form.querySelector('input[name="CRAFT_CSRF_TOKEN"]');

        if (!this.$input) {
            console.error('Unable to find QuickStream form placeholder for [data-fui-quickstream-form]');

            return;
        }

        this.publishableKey = settings.publishableKey;
        this.supplierBusinessCode = settings.supplierBusinessCode;
        this.threeDS2Enabled = settings.threeDS2Enabled || false;
        this.currency = settings.currency;
        this.isTestGateway = (typeof settings.isTestGateway == 'undefined' || settings.isTestGateway == false) ? false : true;
        this.amountType = settings.amountType;
        this.amountFixed = settings.amountFixed;
        this.amountVariable = settings.amountVariable;
        this.quickstreamScriptId = 'FORMIE_QUICKSTREAM_SCRIPT';

        this.challengeFrame = false;
        this.challengeOptions = {
            challengeWindowSize: '04',
            challengeMode: 'callback',
            // challengeMode: 'post',
        };

        if (!this.publishableKey) {
            console.error('Missing publishableKey for QuickStream.');

            return;
        }

        if (!this.supplierBusinessCode) {
            console.error('Missing supplierBusinessCode for QuickStream.');

            return;
        }

        // We can start listening for the field to become visible to initialize it
        this.initialized = true;
    }

    onShow() {
        // Initialize the field only when it's visible
        this.initField();
    }

    onHide() {
        // Field is hidden, so reset everything
        this.onAfterSubmit();

        // Remove unique event listeners
        // TODO: review these work
        this.form.removeEventListener(eventKey('onFormiePaymentValidate', 'quickstream'));
        this.form.removeEventListener(eventKey('onAfterFormieSubmit', 'quickstream'));
        this.form.removeEventListener(eventKey('FormiePaymentQuickstream3DS', 'quickstream'));
    }

    initField() {
        // Fetch and attach the script only once - this is in case there are multiple forms on the page.
        // They all go to a single callback which resolves its loaded state'
        if (!document.getElementById(this.quickstreamScriptId)) {
            const $script = document.createElement('script');
            $script.id = this.quickstreamScriptId;
            $script.src = (this.isTestGateway == false) ? 'https://api.quickstream.westpac.com.au/rest/v1/quickstream-api-1.0.min.js' : 'https://api.quickstream.support.qvalent.com/rest/v1/quickstream-api-1.0.min.js';

            if (this.isTestGateway == true) { console.info(`Quickstream Trusted Frame was loaded in Dev/test mode via ${$script.src}`); }

            $script.async = true;
            $script.defer = true;

            // Wait until quickstream-api.js has loaded, then initialize
            $script.onload = () => {
                this.mountTrustedFrame();
            };

            document.body.appendChild($script);
        } else {
            // Ensure that QuickStream has been loaded and ready to use
            ensureVariable('QuickstreamAPI').then(() => {
                this.mountTrustedFrame();
            });
        }

        // Attach custom event listeners on the form
        if (!this.boundEvents) {
            // TODO: Review this works
            this.form.addEventListener(this.$form, eventKey('onFormiePaymentValidate', 'quickstream'), this.onValidate.bind(this));
            this.form.addEventListener(this.$form, eventKey('onAfterFormieSubmit', 'quickstream'), this.onAfterSubmit.bind(this));
            this.form.addEventListener(this.$form, eventKey('FormiePaymentQuickstream3DS', 'quickstream'), this.onValidate3DS.bind(this));

            this.boundEvents = true;
        }
    }

    /**
     * required if the form is configured to also use 3D Secure, and the card being used is also enrolled
     * - this is an additional security check that can sometimes trigger an additional challenge step
     */
    request3DSecureAuth(singleUseTokenId) {
        return fetch('/actions/formie/integrations/quickstream/request-3d-secure-auth', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                singleUseTokenId,
                CRAFT_CSRF_TOKEN: this.$csrfToken.value,
                params: {
                    principalAmount: Number(this.form.$form.elements['fields[paymentAmount]'].value) ?? null,
                    email: this.form.$form.elements['fields[emailAddress]'].value ?? null,
                    acctID: this.form.$form.elements['fields[customerReferenceNumber]'].value ?? null,
                },
                liveMode: !this.isTestGateway,
            }),
        });
    }

    mountTrustedFrame() {
        // See more at: https://quickstream.westpac.com.au/docs/quickstreamapi/v1/quickstream-api-js/
        // console.info('mounting card with 3Dsecure set to', this.threeDS2Enabled);
        const inputStyle = {
            height: 'auto',
            padding: '0.5rem 0.75rem',
            'font-size': '0.875rem',
            border: '2px solid #c7dfe7',
            'border-radius': '0.25rem',
        };

        const options = {
            config: {
                supplierBusinessCode: this.supplierBusinessCode, // This is a required config option
                threeDS2: this.threeDS2Enabled,
            },
            iframe: {
                width: '100%',
                height: '100%',
                style: {
                    'font-size': '14px',
                    'line-height': '24px',
                    border: '1px solid #dedede',
                    'border-radius': '2px',
                    'margin-bottom': '0.75rem',
                    'min-height': '420px',
                    padding: '1.5rem',
                    width: '100%',
                    'background-color': 'white',
                },
            },
            showAcceptedCards: false,
            cardholderName: {
                style: inputStyle,
                label: 'Name on card',
            },
            cardNumber: {
                style: inputStyle,
                label: 'Card number',
            },
            expiryDateMonth: {
                style: inputStyle,
            },
            expiryDateYear: {
                style: inputStyle,
            },
            cvn: {
                hidden: false,
                label: 'Security code',
            },
            body: {
                style: {},
            },
        };

        QuickstreamAPI.init({
            publishableApiKey: this.publishableKey,
        });

        QuickstreamAPI.creditCards.createTrustedFrame(options, (errors, data) => {
            if (errors) {
                // Handle errors here
                console.error(`Error creating trusted frame: ${errors}`);
            } else {
                this.trustedFrame = data.trustedFrame;
            }
        });

    }

    mountChallengeFrame() {
        QuickstreamAPI.creditCards.createChallengeFrame(this.challengeOptions, (errors, data) => {
            if (errors) {
                // Handle errors here
                console.error('Error creating challenge frame:', errors);
                throw new Error('Error creating challenge frame');
            } else {
                this.challengeFrame = data;
                // console.info('Challenge frame created', this.challengeFrame);
            }
        });
    }

    onValidate(e) {
        // Don't validate if we're not submitting (going back, saving)
        // Check if the form has an invalid flag set, don't bother going further
        // options = https://quickstream.westpac.com.au/docs/quickstreamapi/v1/quickstream-api-js/#trustedframeconfigobject
        if (this.form.submitAction !== 'submit' || e.detail.invalid) {
            return;
        }
        e.preventDefault();

        // Save for later to trigger real submit
        this.submitHandler = e.detail.submitHandler;

        this.removeError();

        if (this.trustedFrame) {
            this.trustedFrame.submitForm((errors, data) => {

                // keep the token handy
                const theToken = (typeof data?.singleUseToken?.singleUseTokenId !== 'undefined') ? data.singleUseToken.singleUseTokenId : false;

                if (errors || !theToken) {
                    // console.warn('Error validating Trusted Frame:', errors);
                    // this.addError('An error occured when processing your payment. Please review and try again.');

                    // reset the submit button (only required if we don't call this.addError() above)
                    this.$submitButton.classList.remove('disabled', 'fui-loading');
                    this.$submitButton.removeAttribute('disabled');

                } else if (typeof data?.singleUseToken?.creditCard?.threeDS2AuthRequired !== 'undefined' && data.singleUseToken.creditCard.threeDS2AuthRequired) {

                    // Additional 3D Secure check required:
                    const threeDSresponse = this.request3DSecureAuth(theToken)
                        .then((response) => {
                            return response.json();
                        })
                        .then((threeDsecureResponse) => {

                            let threeDsMsg;

                            switch (threeDsecureResponse.threeDsStatus) {
                                case 'frictionless':
                                    // all good, append the single use token to the Formie form, and submit
                                    this.updateInputs('quickstreamTokenId', theToken);
                                    this.submitHandler.submitForm();
                                    return true;

                                case 'challenge':
                                    // 3D Secure challenge required
                                    this.challengeOptions.singleUseTokenId = theToken;

                                    // append some callbacks to the challenge options
                                    this.challengeOptions.onSuccess = () => {
                                        // remove the challenge iframe and handle success
                                        this.updateInputs('quickstreamTokenId', theToken);
                                        this.challengeFrame.destroy();
                                        this.submitHandler.submitForm();
                                    },
                                    this.challengeOptions.onFailure = () => {
                                        // remove the challenge iframe and handle failure
                                        this.challengeFrame.destroy();
                                        this.addError('3D secure authentication failed.  Your payment could not be processed.  Please review and try again.');
                                    };
                                    this.mountChallengeFrame();

                                    break;

                                case 'failed':
                                case 'error':
                                    // 3D Secure failed - return false
                                    threeDsMsg = threeDsecureResponse.message || 'Your payment was not successful. Please try a different payment method.';
                                    this.addError(threeDsMsg);
                                    // throw new Error('3D Secure failed');
                                    break;
                            }

                            return true;

                        })
                        .catch((error) => {
                            // this.addError('An error occured when processing your payment. Please review and try again.');
                            console.error('Error validating 3D Secure:', error);
                        });

                } else {
                    // No additional 3D Secure check required:
                    // all good, append the single use token to the Formie form, and submit
                    this.updateInputs('quickstreamTokenId', theToken);
                    this.submitHandler.submitForm();
                }
            });

        } else {
            console.error('Credit Card Frame is invalid.');
        }
    }

    onValidate3DS(e) {
        // console.log('onValidate3DS', e.detail);
    }

    onAfterSubmit(e) {
        // Clear the form
        if (this.trustedFrame) {
            this.trustedFrame.teardown((errors, data) => {
                if (errors) {
                    // Handle errors here
                    console.error('Errors when destroying Trusted Frame:', errors);
                } else {
                    // console.log("Trusted Frame has been destroyed.");
                }
            });
            this.trustedFrame = null;
        }

        // Reset all hidden inputs
        this.updateInputs('quickstreamTokenId', '');
    }
}

window.FormieQuickStream = FormieQuickStream;
