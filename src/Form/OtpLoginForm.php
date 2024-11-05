<?php

namespace Drupal\register_user\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class Otp Login Form.
 */
class OtpLoginForm extends FormBase {
  use StringTranslationTrait;
  /**
   * Drupal\Core\Entity\EntityTypeManagerInterface definition.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Drupal\Core\TempStore\PrivateTempStoreFactory definition.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $tempstorePrivate;

  /**
   * Drupal\Core\Messenger\MessengerInterface definition.
   *
   * @var Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Tempstore mobile no.
   *
   * @var "instance->tempstorePrivate->get'dhi_user_register'"
   */
  protected $tempstoreMobileNo;

  /**
   * Drupal\twilio\Services\Sms definition.
   *
   * @var Drupal\twilio\Services\Sms
   */
  protected $twilio;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->tempstorePrivate = $container->get('tempstore.private');
    $instance->tempstoreMobileNo = $instance->tempstorePrivate->get('dhi_user_register');
    $instance->messenger = $container->get('messenger');
    $instance->twilio = $container->get('twilio.sms');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'otp_login_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $mobile_no = $this->tempstoreMobileNo->get('login_mobile_no');

    $form['mobile_no'] = [
      '#title' => $this->t('Mobile number'),
      '#type' => 'textfield',
      '#default_value' => !empty($mobile_no) ?? '',
      '#description' => $this->t('Enter your mobile phone number'),
      '#required' => TRUE,
    ];

    $form['submit'] = [
      '#value' => $this->t('Get one-time code'),
      '#type' => 'submit',
    ];
    $text = $this->t('Login with a password instead');
    $form['login_with_password_link'] = [
      '#type' => 'markup',
      '#markup' => '<p class="login-password-link"><a href="/user/login">' . $text . '</a></p>',
      '#prefix' => '<div class="form-item create-account-link">',
      '#suffix' => '</div>',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $mobile_no = $form_state->getValue('mobile_no');
    $cleaned_number = preg_replace('/\D/', '', $mobile_no);
    if (strlen($cleaned_number) < 10) {
      $form_state->setErrorByName('mobile_no', $this->t('Please enter a valid mobile number.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $mobile_no = $form_state->getValue('mobile_no');
    if ($mobile_no) {
      $user_storage = $this->entityTypeManager->getStorage('user');
      $user = $user_storage->loadByProperties(['field_mobile_number' => $mobile_no]);
      if ($user) {
        $user = array_values($user)[0];
        $otp = dhi_user_generate_login_otp();
        $current_ts = strtotime('now');
        $mobile_number = '+1' . $mobile_no;
        $message = "Your login code is: " . $otp ;
        $sid = $this->twilio->messageSend($mobile_number, $message);
        if ($sid == 'not send') {
          $this->messenger->addError($this->t('Failed to send SMS.'));
        }
        else {
          $user->set('field_otp_verify', $otp);
          $user->set('field_otp_generate_timestamp', $current_ts);
          $user->save();
          $this->tempstoreMobileNo->set('mobile_number', $mobile_no);
          $form_state->setRedirect("register_user.user_login_otp_verify_form");
        }
      }
      else {
        $this->messenger->addError($this->t('Please enter a valid mobile number.'));
      }
    }
  }

}
