<?php

namespace Drupal\field_builder\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;



/**
 * Class BuildFields.
 */
class BuildFields extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'build_fields';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $options = [];
    $content_types = \Drupal::service('entity_type.bundle.info')
      ->getBundleInfo('node');
    foreach ($content_types as $machine_name => $info) {
      $options[$machine_name] = $info['label'];
    }

    $form['upload_file'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Upload File'),
      '#description' => $this->t('Upload comma separated list of required fields'),
      '#upload_location' => 'public://field_docs',
      '#upload_validators' => [
        'file_validate_extensions' => ['txt'],
      ],
      '#weight' => '0',
    ];
    $form['content_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Content Type'),
      '#description' => $this->t('Attach fields to what content type?'),
      '#options' => $options,
      '#weight' => '0',
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    foreach ($form_state->getValues() as $key => $value) {
      // @TODO: Validate fields.
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $fid = $values['upload_file'][0];
    $file = File::load($fid);
    $data = file_get_contents($file->getFileUri());
    $candidates = preg_split('/\s+/', $data, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($candidates as $key => $candidate) {
      if (substr( $candidate, 0, 6 ) !== "field_") {
        $candidates[$key] = 'field_' . $candidate;
      }
    }
    $file->delete();
    $entityFieldManager = \Drupal::service('entity_field.manager');
    $fields = $entityFieldManager->getFieldDefinitions('node', $values['content_type']);
    $field_names = \array_keys($fields);
    $dealt_with = \array_intersect($candidates, $field_names);
    $work = array_diff($candidates, $dealt_with);
    foreach($work as $term) {
      $exists = FieldStorageConfig::loadByName('node', $term);
      if (!$exists) {
        $field_storage = FieldStorageConfig::create([
          'entity_type' => 'node',
          'field_name' => $term,
          'type' => 'text',
        ]);
        $field_storage->save();
      }
      $field_storage = FieldStorageConfig::loadByName('node', $term);
      $label = \str_replace('field_', '', $term);
      $label = \str_replace('_', ' ', $label);
      $label = \ucwords($label);

      FieldConfig::create([
        'field_storage' => $field_storage,
        'bundle' => $values['content_type'],
        'label' => $label,
      ])->save();
    }

    // Display result.
    foreach ($work as $term) {
      \Drupal::messenger()
        ->addMessage("Added $term to  {$values['content_type']}");
    }
  }

}
