<?php

namespace Drupal\field_builder\Form;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;




/**
 * Class BuildFields.
 */
class BuildFields extends FormBase {


  /**
   * The entity type bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $bundleInfo;

  /**
   * Entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;


  public function __construct(EntityFieldManagerInterface $entity_field_manager, EntityTypeBundleInfoInterface $bundle_info) {
    $this->entityFieldManager = $entity_field_manager;
    $this->bundleInfo = $bundle_info;
  }
  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'build_fields';
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_field.manager'),
      $container->get('entity_type.bundle.info')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $options = [];
    $content_types = $this->bundleInfo->getBundleInfo('node');
    foreach ($content_types as $machine_name => $info) {
      $options[$machine_name] = $info['label'];
    }

    $form['upload_file'] = [
      '#type' => 'file',
      '#title' => $this->t('Upload File'),
      '#description' => $this->t('Upload space separated list of required fields'),
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
    $validators = ['file_validate_extensions' => ['txt']];
    if ($file = file_save_upload('upload_file', $validators, FALSE, 0)) {
      $data = file_get_contents($file->getFileUri());
    }
    $values = $form_state->getValues();
    $candidates = preg_split('/\s+/', $data, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($candidates as $key => $candidate) {
      if (substr( $candidate, 0, 6 ) !== "field_") {
        $candidates[$key] = 'field_' . $candidate;
      }
    }
    $file->delete();
    $fields = $this->entityFieldManager->getFieldDefinitions('node', $values['content_type']);
    $field_names = \array_keys($fields);
    $dealt_with = \array_intersect($candidates, $field_names);
    $work = array_diff($candidates, $dealt_with);
    foreach($work as $term) {
      $exists = FieldStorageConfig::loadByName('node', $term);
      if (!$exists) {
        $field_storage = FieldStorageConfig::create([
          'entity_type' => 'node',
          'field_name' => $term,
          'type' => 'string',
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
