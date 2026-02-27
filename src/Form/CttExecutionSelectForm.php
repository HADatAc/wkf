<?php

namespace Drupal\ctt\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

class CttExecutionSelectForm extends FormBase {

  public function getFormId(): string {
    return 'ctt_execution_select_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, string $studyuri = NULL): array {
    $decodedStudyUri = $studyuri ? base64_decode($studyuri) : NULL;
    if (empty($decodedStudyUri)) {
      $this->messenger()->addError($this->t('Invalid study URI.'));
      return [];
    }

    $api = \Drupal::service('rep.api_connector');
    $userEmail = \Drupal::currentUser()->getEmail();
    $state = \Drupal::state();
    $storedProcessUri = $state->get('ctt.study_process.' . sha1($decodedStudyUri));

    // Prefer processes associated to this study (Option 3).
      // In this deployment, hascoapi does not support manageremailbystudy for process.
      // So selection is from all workflows managed by the current user.
      $processes = $api->parseObjectResponse(
        $api->listByManagerEmail('workflow', $userEmail, 9999, 0),
        'listByManagerEmail'
      );
      if ($processes === NULL) {
        $processes = [];
      }

    $options = [];
    if (is_array($processes)) {
      foreach ($processes as $proc) {
        if (!is_object($proc) || empty($proc->uri)) {
          continue;
        }
        $label = !empty($proc->label) ? $proc->label : $proc->uri;
        $derived = NULL;
        if (!empty($proc->wasDerivedFrom)) {
          if (is_array($proc->wasDerivedFrom)) {
            $derived = reset($proc->wasDerivedFrom) ?: NULL;
          }
          elseif (is_string($proc->wasDerivedFrom)) {
            $derived = $proc->wasDerivedFrom;
          }
        }
        $stem = (!empty($derived)) ? (' (stem: ' . $derived . ')') : '';
        $options[$proc->uri] = $label . ' [' . $proc->uri . ']' . $stem;
      }
    }

    $form['studyuri'] = [
      '#type' => 'hidden',
      '#value' => $studyuri,
    ];

    $form['processUri'] = [
      '#type' => 'select',
      '#title' => $this->t('Workflow / Process'),
      '#options' => $options,
      '#default_value' => (!empty($storedProcessUri) && isset($options[$storedProcessUri])) ? $storedProcessUri : NULL,
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
      '#description' => $this->t('Select which workflow/process to execute for this study.'),
    ];

    if (empty($options)) {
      $form['processUri']['#disabled'] = TRUE;
      $form['processUri']['#required'] = FALSE;
      $form['message'] = [
        '#type' => 'markup',
        '#markup' => '<p>No workflows/processes were found for your user. Create or ingest a workflow first.</p>',
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Run'),
      '#disabled' => empty($options),
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('std.manage_study_elements', ['studyuri' => $studyuri]),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $processUri = (string) $form_state->getValue('processUri');
    if ($processUri === '') {
      $form_state->setErrorByName('processUri', $this->t('Please select a workflow/process.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $studyuri = (string) $form_state->getValue('studyuri');
    $processUri = (string) $form_state->getValue('processUri');

    // Persist association locally (Drupal-only) so future runs can be inferred by study.
    $decodedStudyUri = base64_decode($studyuri);
    if (!empty($decodedStudyUri) && !empty($processUri)) {
      \Drupal::state()->set('ctt.study_process.' . sha1($decodedStudyUri), $processUri);
    }

    $form_state->setRedirect('ctt.execution_create', ['studyuri' => $studyuri], [
      'query' => [
        'processUri' => $processUri,
      ],
    ]);
  }

}
