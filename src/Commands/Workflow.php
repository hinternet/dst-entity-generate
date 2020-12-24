<?php

namespace Drupal\dst_entity_generate\Commands;

use Consolidation\AnnotatedCommand\CommandResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\dst_entity_generate\BaseEntityGenerate;
use Drupal\dst_entity_generate\DstegConstants;
use Drupal\dst_entity_generate\Services\GeneralApi;
use Drupal\dst_entity_generate\Services\GoogleSheetApi;

/**
 * Class provides functionality of workflows, states generation from DST sheet.
 *
 * @package Drupal\dst_entity_generate\Commands
 */
class Workflow extends BaseEntityGenerate {

  /**
   * DstEntityGenerate constructor.
   *
   * @param \Drupal\dst_entity_generate\Services\GoogleSheetApi $googleSheetApi
   *   Google Sheet Api service definition.
   * @param \Drupal\dst_entity_generate\Services\GeneralApi $generalApi
   *   GeneralApi service definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(GoogleSheetApi $googleSheetApi,
                              GeneralApi $generalApi,
                              ConfigFactoryInterface $configFactory) {
    parent::__construct($googleSheetApi, $generalApi, $configFactory);
  }

  /**
   * Generate all the Drupal workflows from Drupal Spec tool sheet.
   *
   * @command dst:generate:workflow
   * @aliases dst:w
   * @usage drush dst:generate:workflow
   */
  public function generateWorkflows() {
    $result = FALSE;
    $workflow_enabled = $this->helper->isModuleEnabled('workflows');
    $content_moderation_enabled = $this->helper->isModuleEnabled('content_moderation');
    if (!$workflow_enabled) {
      $this->showMessageOnCli($this->t('Please install workflows module.'));
    }
    elseif (!$content_moderation_enabled) {
      $this->showMessageOnCli($this->t('Please install content moderation module.'));
    }
    else {
      try {
        $default_weight = 0;
        $this->yell($this->t('Generating Workflows.'), 100, 'blue');
        $workflow_storage = $this->helper->getAllEntities('workflow');
        $google_sheet_api = $this->sheet;
        $workflow_map = [];

        // Get workflows from the sheet and prepare a map.
        $workflows = $google_sheet_api->getData(DstegConstants::WORKFLOWS);
        foreach ($workflows as $workflow) {
          if ($workflow['x'] === 'w') {
            $workflow_map[$workflow['machine_name']] = $workflow['label'];
          }
        }

        // Get workflow states and transitions from the sheet.
        $workflow_states = $google_sheet_api->getData(DstegConstants::WORKFLOW_STATES);
        $workflow_transitions = $google_sheet_api->getData(DstegConstants::WORKFLOW_TRANSITIONS);

        // Create the workflow config with all states and transitions data.
        $workflow_config = [];
        $workflow_state_map = [];
        foreach ($workflow_map as $wf_machine_name => $wf_label) {
          $workflow_config['type_settings']['states'] = [];
          $workflow_config['type_settings']['transitions'] = [];

          // Add only non implemented work flow states.
          foreach ($workflow_states as $workflow_state) {
            if ($workflow_state['x'] === 'w' && $workflow_state['workflow'] === $wf_label) {
              $workflow_config['type_settings']['states'][$workflow_state['machine_name']] = [
                'label' => $workflow_state['label'],
                'weight' => $default_weight,
              ];
              $workflow_state_map[$workflow_state['machine_name']] = $workflow_state['label'];
              $this->say($this->t('State @wf_state created successfully for workflow @workflow', [
                '@wf_state' => $workflow_state['label'],
                '@workflow' => $wf_label,
              ]));
            }
          }

          // Add only non implemented work flow transitions.
          foreach ($workflow_transitions as $workflow_transition) {
            if ($workflow_transition['x'] === 'w' && $workflow_transition['workflow'] === $wf_label) {
              // Create transition from the array.
              $workflow_transition_from[$workflow_transition['machine_name']][] = array_search(
                $workflow_transition['from_state'],
                $workflow_state_map
              );
              $workflow_transition_to = array_search($workflow_transition['to_state'], $workflow_state_map);
              if (empty($workflow_transition_from)) {
                $this->showMessageOnCli($this->t('From states not present for workflow @workflow', [
                  '@workflow' => $wf_label,
                ]));
                $this->showMessageOnCli($this->t('Transitions @wf_trans not created workflow @workflow', [
                  '@wf_trans' => $workflow_transition['label'],
                  '@workflow' => $wf_label,
                ]));
              }
              elseif (empty($workflow_transition_to)) {
                $this->showMessageOnCli($this->t('To state @to_state is not present for workflow @workflow', [
                  '@to_state' => $workflow_transition['to_state'],
                  '@workflow' => $wf_label,
                ]));
                $this->showMessageOnCli($this->t('Transitions @wf_trans not created for workflow @workflow', [
                  '@wf_trans' => $workflow_transition['label'],
                  '@workflow' => $wf_label,
                ]));
              }
              else {
                $workflow_config['type_settings']['transitions'][$workflow_transition['machine_name']] = [
                  'label' => $workflow_transition['label'],
                  'from' => $workflow_transition_from[$workflow_transition['machine_name']],
                  'to' => $workflow_transition_to,
                  'weight' => $default_weight,
                ];
                $this->showMessageOnCli($this->t('Transitions @wf_trans created successfully for workflow @workflow', [
                  '@wf_trans' => $workflow_transition['label'],
                  '@workflow' => $wf_label,
                ]));
              }
            }
          }
          $is_workflow_present = $workflow_storage->load($wf_machine_name);
          if (isset($is_workflow_present) || !empty($is_workflow_present)) {
            // Set states and transitions if workflow is present.
            $type_settings = $is_workflow_present->get('type_settings');
            if (!empty($workflow_config['type_settings']['states'])) {
              $type_settings['states'] = array_merge($type_settings['states'], $workflow_config['type_settings']['states']);
            }
            if (!empty($workflow_config['type_settings']['transitions'])) {
              $type_settings['transitions'] = array_merge($type_settings['transitions'], $workflow_config['type_settings']['transitions']);
            }
            $is_workflow_present->set('type_settings', $type_settings);
            $is_workflow_present->save();
          }
          else {
            // Create a new workflow.
            $workflow_config['id'] = $wf_machine_name;
            $workflow_config['label'] = $wf_label;
            $workflow_config['type'] = 'content_moderation';
            $is_workflow_saved = $workflow_storage
              ->create($workflow_config)
              ->save();
            if ($is_workflow_saved === 1) {
              $this->showMessageOnCli($this->t('New workflow @workflow created along with states and transitions.', [
                '@workflow' => $wf_label,
              ]));
            }
          }
        }
        $this->yell($this->t('Finished generating workflows, states and transitions.'), 100, 'blue');
        $result = self::EXIT_SUCCESS;
      }
      catch (\Exception $exception) {
        $this->displayAndLogException($exception, DstegConstants::WORKFLOWS);
        $result = self::EXIT_FAILURE;
      }
    }
    return CommandResult::exitCode($result);
  }

  /**
   * Helper function to say message on cli as well log them.
   *
   * @param string $message
   *   The translated message string.
   */
  private function showMessageOnCli(string $message) {
    $this->helper->logMessage([$message]);
    $this->say($message);
  }

}
