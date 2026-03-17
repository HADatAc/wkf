<?php

namespace Drupal\ctt\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for the CTT Workflow Editor module.
 */
class CttSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ctt.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ctt_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ctt.settings');

    // Prefer existing CTT config; fallback to REP config if available.
    $default_hasco_api_url = (string) ($config->get('hasco_api_url') ?? '');
    if ($default_hasco_api_url === '' && \Drupal::moduleHandler()->moduleExists('rep')) {
      try {
        $rep_url = (string) \Drupal\rep\Utils::configApiUrl();
        $rep_url = trim($rep_url);
        if ($rep_url !== '' && strpos($rep_url, 'http://x.x.x.x:9000') === FALSE) {
          $default_hasco_api_url = $rep_url;
        }
      }
      catch (\Exception $e) {
        // Ignore.
      }
    }
    if ($default_hasco_api_url === '') {
      $default_hasco_api_url = 'http://localhost:9000';
    }

    $form['hasco_api_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('HASCO API URL'),
      '#description' => $this->t('Base URL of the hascoapi Play Framework backend. In production, this must NOT be localhost. If REP is configured, this field will default to REP\'s api_url.'),
      '#default_value' => $default_hasco_api_url,
      '#required' => TRUE,
    ];

    $form['jwt_key_id'] = [
      '#type' => 'key_select',
      '#title' => $this->t('JWT Secret Key'),
      '#description' => $this->t('Select the Key that stores the JWT secret (pac4j.jwt.secret from hascoapi application.conf).'),
      '#default_value' => $config->get('jwt_key_id') ?: '',
    ];

    $form['disable_ssl_verification'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Disable SSL verification'),
      '#description' => $this->t('Only use for local development. Do NOT enable in production.'),
      '#default_value' => $config->get('disable_ssl_verification') ?: FALSE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $url = $form_state->getValue('hasco_api_url');
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
      $form_state->setErrorByName('hasco_api_url', $this->t('Please enter a valid URL.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('ctt.settings')
      ->set('hasco_api_url', rtrim($form_state->getValue('hasco_api_url'), '/'))
      ->set('jwt_key_id', $form_state->getValue('jwt_key_id'))
      ->set('disable_ssl_verification', (bool) $form_state->getValue('disable_ssl_verification'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
