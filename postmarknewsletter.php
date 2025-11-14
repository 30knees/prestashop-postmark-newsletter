<?php
/**
 * Postmark Newsletter Module
 *
 * @author    Your Name
 * @copyright Copyright (c) 2025
 * @license   MIT License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/PostmarkClient.php';
require_once dirname(__FILE__) . '/classes/BounceHandler.php';
require_once dirname(__FILE__) . '/classes/NewsletterQueue.php';

class PostmarkNewsletter extends Module
{
    protected $config_form = false;

    public function __construct()
    {
        $this->name = 'postmarknewsletter';
        $this->tab = 'emailing';
        $this->version = '1.0.0';
        $this->author = 'Your Name';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '8.0.0',
            'max' => _PS_VERSION_
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Postmark Newsletter');
        $this->description = $this->l('Send newsletters using Postmark with automatic bounce handling and unsubscribe functionality.');

        $this->confirmUninstall = $this->l('Are you sure you want to uninstall? All newsletter logs and settings will be deleted.');
    }

    /**
     * Install the module
     */
    public function install()
    {
        include(dirname(__FILE__) . '/sql/install.sql');

        return parent::install()
            && $this->installSQL()
            && $this->registerHook('displayBackOfficeHeader')
            && $this->registerHook('displayAdminCustomers')
            && Configuration::updateValue('POSTMARK_API_TOKEN', '')
            && Configuration::updateValue('POSTMARK_FROM_EMAIL', '')
            && Configuration::updateValue('POSTMARK_FROM_NAME', '')
            && Configuration::updateValue('POSTMARK_AUTO_UNSUBSCRIBE_HARD', 1)
            && Configuration::updateValue('POSTMARK_AUTO_UNSUBSCRIBE_SOFT', 0)
            && Configuration::updateValue('POSTMARK_SOFT_BOUNCE_THRESHOLD', 3)
            && Configuration::updateValue('POSTMARK_TRACK_OPENS', 1)
            && Configuration::updateValue('POSTMARK_TRACK_LINKS', 1)
            && Configuration::updateValue('POSTMARK_MESSAGE_STREAM', 'broadcast');
    }

    /**
     * Uninstall the module
     */
    public function uninstall()
    {
        include(dirname(__FILE__) . '/sql/uninstall.sql');

        return $this->uninstallSQL()
            && Configuration::deleteByName('POSTMARK_API_TOKEN')
            && Configuration::deleteByName('POSTMARK_FROM_EMAIL')
            && Configuration::deleteByName('POSTMARK_FROM_NAME')
            && Configuration::deleteByName('POSTMARK_AUTO_UNSUBSCRIBE_HARD')
            && Configuration::deleteByName('POSTMARK_AUTO_UNSUBSCRIBE_SOFT')
            && Configuration::deleteByName('POSTMARK_SOFT_BOUNCE_THRESHOLD')
            && Configuration::deleteByName('POSTMARK_TRACK_OPENS')
            && Configuration::deleteByName('POSTMARK_TRACK_LINKS')
            && Configuration::deleteByName('POSTMARK_MESSAGE_STREAM')
            && parent::uninstall();
    }

    /**
     * Execute SQL from install.sql
     */
    protected function installSQL()
    {
        $sql = file_get_contents(dirname(__FILE__) . '/sql/install.sql');
        $sql = str_replace('PREFIX_', _DB_PREFIX_, $sql);
        $sql = preg_split("/;\s*[\r\n]+/", $sql);

        foreach ($sql as $query) {
            $query = trim($query);
            if (!empty($query)) {
                if (!Db::getInstance()->execute($query)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Execute SQL from uninstall.sql
     */
    protected function uninstallSQL()
    {
        $sql = file_get_contents(dirname(__FILE__) . '/sql/uninstall.sql');
        $sql = str_replace('PREFIX_', _DB_PREFIX_, $sql);
        $sql = preg_split("/;\s*[\r\n]+/", $sql);

        foreach ($sql as $query) {
            $query = trim($query);
            if (!empty($query)) {
                if (!Db::getInstance()->execute($query)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Load the configuration form
     */
    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitPostmarkNewsletterModule')) {
            $output .= $this->postProcess();
        }

        if (Tools::isSubmit('testPostmarkConnection')) {
            $output .= $this->testConnection();
        }

        if (Tools::isSubmit('sendTestNewsletter')) {
            $output .= $this->sendTestNewsletter();
        }

        $this->context->smarty->assign(array(
            'module_dir' => $this->_path,
            'stats' => $this->getDashboardStats(),
        ));
        $this->context->controller->addCSS($this->_path . 'views/css/admin.css');
        $this->context->controller->addJS($this->_path . 'views/js/admin.js');

        $output .= $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');
        $output .= $this->renderForm();

        return $output;
    }

    /**
     * Create the form that will be displayed in the configuration page
     */
    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitPostmarkNewsletterModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigForm()));
    }

    /**
     * Create the structure of the form
     */
    protected function getConfigForm()
    {
        $webhookUrl = $this->context->link->getModuleLink($this->name, 'webhook', array(), true);

        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Postmark API Settings'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('Postmark Server API Token'),
                        'name' => 'POSTMARK_API_TOKEN',
                        'size' => 50,
                        'required' => true,
                        'desc' => $this->l('Your Postmark Server API token from your Postmark account.')
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('From Email'),
                        'name' => 'POSTMARK_FROM_EMAIL',
                        'size' => 50,
                        'required' => true,
                        'desc' => $this->l('Sender email address (must be verified in Postmark).')
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('From Name'),
                        'name' => 'POSTMARK_FROM_NAME',
                        'size' => 50,
                        'required' => true,
                        'desc' => $this->l('Sender name that will appear in emails.')
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Message Stream'),
                        'name' => 'POSTMARK_MESSAGE_STREAM',
                        'size' => 50,
                        'required' => true,
                        'desc' => $this->l('Postmark message stream (default: "broadcast").')
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Track Opens'),
                        'name' => 'POSTMARK_TRACK_OPENS',
                        'is_bool' => true,
                        'desc' => $this->l('Track email opens in Postmark.'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Track Links'),
                        'name' => 'POSTMARK_TRACK_LINKS',
                        'is_bool' => true,
                        'desc' => $this->l('Track link clicks in Postmark.'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Auto-unsubscribe Hard Bounces'),
                        'name' => 'POSTMARK_AUTO_UNSUBSCRIBE_HARD',
                        'is_bool' => true,
                        'desc' => $this->l('Automatically unsubscribe recipients on hard bounces.'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Auto-unsubscribe Soft Bounces'),
                        'name' => 'POSTMARK_AUTO_UNSUBSCRIBE_SOFT',
                        'is_bool' => true,
                        'desc' => $this->l('Automatically unsubscribe recipients after threshold soft bounces.'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Soft Bounce Threshold'),
                        'name' => 'POSTMARK_SOFT_BOUNCE_THRESHOLD',
                        'size' => 10,
                        'desc' => $this->l('Number of soft bounces before auto-unsubscribe (default: 3).')
                    ),
                    array(
                        'type' => 'html',
                        'label' => $this->l('Webhook URL'),
                        'name' => 'webhook_url',
                        'html_content' => '<div class="alert alert-info">
                            <strong>' . $this->l('Configure this URL in your Postmark account:') . '</strong><br>
                            <code>' . $webhookUrl . '</code><br>
                            <small>' . $this->l('Enable webhooks for: Bounce, Delivery, and Spam Complaint events.') . '</small>
                        </div>'
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
                'buttons' => array(
                    array(
                        'type' => 'submit',
                        'name' => 'testPostmarkConnection',
                        'title' => $this->l('Test Connection'),
                        'icon' => 'process-icon-cogs',
                        'class' => 'btn btn-default pull-right'
                    ),
                )
            ),
        );
    }

    /**
     * Set values for the inputs
     */
    protected function getConfigFormValues()
    {
        return array(
            'POSTMARK_API_TOKEN' => Configuration::get('POSTMARK_API_TOKEN', ''),
            'POSTMARK_FROM_EMAIL' => Configuration::get('POSTMARK_FROM_EMAIL', ''),
            'POSTMARK_FROM_NAME' => Configuration::get('POSTMARK_FROM_NAME', ''),
            'POSTMARK_MESSAGE_STREAM' => Configuration::get('POSTMARK_MESSAGE_STREAM', 'broadcast'),
            'POSTMARK_TRACK_OPENS' => Configuration::get('POSTMARK_TRACK_OPENS', 1),
            'POSTMARK_TRACK_LINKS' => Configuration::get('POSTMARK_TRACK_LINKS', 1),
            'POSTMARK_AUTO_UNSUBSCRIBE_HARD' => Configuration::get('POSTMARK_AUTO_UNSUBSCRIBE_HARD', 1),
            'POSTMARK_AUTO_UNSUBSCRIBE_SOFT' => Configuration::get('POSTMARK_AUTO_UNSUBSCRIBE_SOFT', 0),
            'POSTMARK_SOFT_BOUNCE_THRESHOLD' => Configuration::get('POSTMARK_SOFT_BOUNCE_THRESHOLD', 3),
        );
    }

    /**
     * Save form data
     */
    protected function postProcess()
    {
        $form_values = $this->getConfigFormValues();

        foreach (array_keys($form_values) as $key) {
            Configuration::updateValue($key, Tools::getValue($key));
        }

        return $this->displayConfirmation($this->l('Settings updated successfully.'));
    }

    /**
     * Test Postmark connection
     */
    protected function testConnection()
    {
        try {
            $client = new PostmarkClient(Configuration::get('POSTMARK_API_TOKEN'));
            $result = $client->testConnection();

            if ($result) {
                return $this->displayConfirmation($this->l('Connection successful! Postmark API is working.'));
            } else {
                return $this->displayError($this->l('Connection failed. Please check your API token.'));
            }
        } catch (Exception $e) {
            return $this->displayError($this->l('Error: ') . $e->getMessage());
        }
    }

    /**
     * Send a test newsletter email using the configured Postmark settings
     */
    protected function sendTestNewsletter()
    {
        $testEmail = Tools::getValue('test_email');

        if (empty($testEmail) || !Validate::isEmail($testEmail)) {
            return $this->displayError($this->l('Please provide a valid test email address.'));
        }

        $apiToken = Configuration::get('POSTMARK_API_TOKEN');
        $fromEmail = Configuration::get('POSTMARK_FROM_EMAIL');
        $fromName = Configuration::get('POSTMARK_FROM_NAME');
        $messageStream = Configuration::get('POSTMARK_MESSAGE_STREAM', 'broadcast');

        if (empty($apiToken) || empty($fromEmail) || empty($fromName)) {
            return $this->displayError($this->l('Please configure the Postmark API token, From email, and From name before sending a test email.'));
        }

        try {
            $client = new PostmarkClient($apiToken);

            $subject = $this->l('Postmark Newsletter Test Email');
            $htmlBody = '<p>' . $this->l('Hello!') . '</p>'
                . '<p>' . $this->l('This is a test email sent from the Postmark Newsletter module configuration page.') . '</p>'
                . '<p>' . $this->l('If you received this message, your Postmark settings are working correctly.') . '</p>';

            $payload = array(
                'From' => sprintf('%s <%s>', $fromName, $fromEmail),
                'To' => $testEmail,
                'Subject' => $subject,
                'HtmlBody' => $htmlBody,
                'MessageStream' => $messageStream,
                'TrackOpens' => (bool)Configuration::get('POSTMARK_TRACK_OPENS', 1),
            );

            if ((bool)Configuration::get('POSTMARK_TRACK_LINKS', 1)) {
                $payload['TrackLinks'] = 'HtmlAndText';
            }

            $client->sendEmail($payload);

            return $this->displayConfirmation($this->l('Test email sent successfully. Please check the inbox of the provided address.'));
        } catch (Exception $e) {
            return $this->displayError($this->l('Failed to send test email: ') . $e->getMessage());
        }
    }

    /**
     * Add CSS for back office header
     */
    public function hookDisplayBackOfficeHeader()
    {
        if (Tools::getValue('configure') == $this->name) {
            $this->context->controller->addCSS($this->_path . 'views/css/admin.css');
            $this->context->controller->addJS($this->_path . 'views/js/admin.js');
        }
    }

    /**
     * Collect statistics for the configuration dashboard
     *
     * @return array
     */
    protected function getDashboardStats()
    {
        $stats = array(
            'total_subscribers' => (int)$this->getTotalSubscribers(),
            'total_sent' => 0,
            'total_bounces' => 0,
            'auto_unsubscribed' => 0,
        );

        $logStats = Db::getInstance()->getRow('
            SELECT
                SUM(CASE WHEN status IN ("sent", "delivered") THEN 1 ELSE 0 END) AS sent_count,
                SUM(CASE WHEN status = "bounced" THEN 1 ELSE 0 END) AS bounce_count
            FROM ' . _DB_PREFIX_ . 'postmark_newsletter_log
        ');

        if ($logStats) {
            $stats['total_sent'] = (int)$logStats['sent_count'];
            $stats['total_bounces'] = (int)$logStats['bounce_count'];
        }

        $bounceStats = BounceHandler::getBounceStats();

        if ($bounceStats) {
            $stats['auto_unsubscribed'] = (int)$bounceStats['auto_unsubscribed'];
        }

        return $stats;
    }

    /**
     * Display customer newsletter stats in admin
     */
    public function hookDisplayAdminCustomers($params)
    {
        if (isset($params['id_customer'])) {
            $id_customer = (int)$params['id_customer'];

            $stats = Db::getInstance()->getRow('
                SELECT
                    COUNT(*) as total_sent,
                    SUM(CASE WHEN status = "bounced" THEN 1 ELSE 0 END) as total_bounced
                FROM ' . _DB_PREFIX_ . 'postmark_newsletter_log
                WHERE id_customer = ' . $id_customer
            );

            $this->context->smarty->assign('postmark_stats', $stats);
            return $this->display(__FILE__, 'views/templates/admin/customer_stats.tpl');
        }
    }

    /**
     * Get newsletter subscribers
     */
    public function getNewsletterSubscribers($limit = null, $offset = 0)
    {
        $sql = 'SELECT c.id_customer, c.email, c.firstname, c.lastname
                FROM ' . _DB_PREFIX_ . 'customer c
                WHERE c.newsletter = 1
                AND c.deleted = 0
                AND c.active = 1
                AND c.email NOT IN (
                    SELECT email FROM ' . _DB_PREFIX_ . 'postmark_bounces
                    WHERE unsubscribed = 1
                )
                ORDER BY c.id_customer ASC';

        if ($limit) {
            $sql .= ' LIMIT ' . (int)$offset . ', ' . (int)$limit;
        }

        return Db::getInstance()->executeS($sql);
    }

    /**
     * Get total number of newsletter subscribers
     */
    public function getTotalSubscribers()
    {
        return (int)Db::getInstance()->getValue('
            SELECT COUNT(*)
            FROM ' . _DB_PREFIX_ . 'customer c
            WHERE c.newsletter = 1
            AND c.deleted = 0
            AND c.active = 1
            AND c.email NOT IN (
                SELECT email FROM ' . _DB_PREFIX_ . 'postmark_bounces
                WHERE unsubscribed = 1
            )
        ');
    }
}
