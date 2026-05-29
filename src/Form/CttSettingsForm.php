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

    // Display the effective API URL (always from REP).
    $rep_url = '';
    if (\Drupal::moduleHandler()->moduleExists('rep')) {
      try {
        $rep_url = trim((string) \Drupal\rep\Utils::configApiUrl());
      }
      catch (\Exception $e) {
        $rep_url = '';
      }
    }

    $form['api_url_note'] = [
      '#type' => 'item',
      '#title' => $this->t('HASCO API URL'),
      '#markup' => $rep_url !== ''
        ? $this->t('Using REP api_url: <strong>@url</strong>', ['@url' => $rep_url])
        : $this->t('Using REP api_url: <strong>(not configured)</strong>'),
      '#description' => $this->t('CTT always uses the same API base URL configured in the REP module.'),
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

    $form['r_analysis_endpoint_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('R analysis endpoint path'),
      '#description' => $this->t('Relative hascoapi path used for real R execution requests (example: /hascoapi/api/r-analysis/execute).'),
      '#default_value' => $config->get('r_analysis_endpoint_path') ?: '/hascoapi/api/r-analysis/execute',
      '#required' => TRUE,
    ];

    $form['r_analysis_timeout_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('R analysis timeout (seconds)'),
      '#description' => $this->t('HTTP timeout when calling the backend R execution endpoint.'),
      '#default_value' => (int) ($config->get('r_analysis_timeout_seconds') ?: 60),
      '#min' => 5,
      '#max' => 300,
      '#step' => 1,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $endpointPath = trim((string) $form_state->getValue('r_analysis_endpoint_path'));
    if ($endpointPath === '' || !str_starts_with($endpointPath, '/')) {
      $form_state->setErrorByName('r_analysis_endpoint_path', $this->t('R analysis endpoint path must start with "/".'));
    }

    $timeout = (int) $form_state->getValue('r_analysis_timeout_seconds');
    if ($timeout < 5 || $timeout > 300) {
      $form_state->setErrorByName('r_analysis_timeout_seconds', $this->t('R analysis timeout must be between 5 and 300 seconds.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('ctt.settings')
      ->set('jwt_key_id', $form_state->getValue('jwt_key_id'))
      ->set('disable_ssl_verification', (bool) $form_state->getValue('disable_ssl_verification'))
      ->set('r_analysis_endpoint_path', trim((string) $form_state->getValue('r_analysis_endpoint_path')))
      ->set('r_analysis_timeout_seconds', (int) $form_state->getValue('r_analysis_timeout_seconds'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
