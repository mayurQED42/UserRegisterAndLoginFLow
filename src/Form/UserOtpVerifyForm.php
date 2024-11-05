<?php


namespace Drupal\register_user\Form;


use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;


/**
* Class User Otp Verify Form.
*/
class UserOtpVerifyForm extends FormBase {
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
  * Symfony\Component\HttpFoundation\RequestStack definition.
  *
  * @var \Symfony\Component\HttpFoundation\RequestStack
  */
 protected $requestStack;


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
   $instance->requestStack = $container->get('request_stack');
   return $instance;
 }


 /**
  * {@inheritdoc}
  */
 public function getFormId() {
   return 'user_otp_verify_form';
 }


 /**
  * {@inheritdoc}
  */
 public function buildForm(array $form, FormStateInterface $form_state) {
   $mobile_no = $this->tempstoreMobileNo->get('mobile_number');
   $form['mobile_no'] = [
     '#title' => $this->t('Mobile number'),
     '#type' => 'textfield',
     '#default_value' => !empty($mobile_no) ? $mobile_no : '',
     '#description' => $this->t('Enter your mobile phone number'),
   ];


   $form['otp'] = [
     '#title' => $this->t('One-time passcode'),
     '#type' => 'textfield',
   ];
   $form['submit'] = [
     '#value' => $this->t('Login'),
     '#type' => 'submit',
   ];
   $form['resend_code'] = [
     '#type' => 'submit',
     '#submit' => [[$this, 'sendOtp']],
     '#value' => $this->t('Re-send code'),
     '#limit_validation_errors' => [],
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
    $otp = $form_state->getValue('otp');
    if (empty(trim($mobile_no))) {
      $form_state->setErrorByName('mobile_no', $this->t('Mobile field is required.'));
    }
    if (empty(trim($otp))) {
      $form_state->setErrorByName('otp', $this->t('One-time passcode field is required.'));
    }
 
 
    $cleaned_number = preg_replace('/\D/', '', $mobile_no);
    if (strlen($cleaned_number) !== 10) {
      $form_state->setErrorByName('mobile_no', $this->t('Please enter a valid mobile number.'));
    }
  }

  /**
  * {@inheritdoc}
  */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $mobile_no = $form_state->getValue('mobile_no');
    $otp = $form_state->getValue('otp');
    if (!empty($mobile_no) && !empty($otp)) {
      $otp = $form_state->getValue('otp');
      $user_storage = $this->entityTypeManager->getStorage('user');
      $user = $user_storage->loadByProperties(['field_mobile_number' => $mobile_no]);
      if ($user) {
        $user = array_values($user)[0];
        $user_otp_value = @$user->get('field_otp_verify')->getValue()[0]['value'];
        $otp_timestamp = @$user->get('field_otp_generate_timestamp')->getValue()[0]['value'];
        $current_timestamp = time();
        if ($user_otp_value == $otp) {
          // Check weather user enter OTP within 10 minutes.
          if ($current_timestamp < ($otp_timestamp + 600)) {
            // Set user to active.
            $user->set('status', 1);
            $user->set('field_otp_verify', '');
            $user->save();
            user_login_finalize($user);
            $form_state->setRedirect('user.page');
          }
          else {
            $this->messenger->addError($this->t('This code has expired or is not valid.'));
          }
        }
        else {
          $this->messenger->addError($this->t('This code has expired or is not valid.'));
        }
      }
    }
  }

  /**
  * Send otp function.
  */
  public function sendOtp(array &$form, FormStateInterface $form_state) {
    $mobile_no = @$form_state->getUserInput('mobile_no')['mobile_no'];
    if ($mobile_no) {
      $user_storage = $this->entityTypeManager->getStorage('user');
      $user = $user_storage->loadByProperties(['field_mobile_number' => $mobile_no]);
      if ($user) {
        $user = array_values($user)[0];
        $otp = register_user_generate_login_otp();
        $current_ts = strtotime('now');
        $mobile_number = '+1' . $mobile_no;
        $message = "Your login code is: " . $otp;
        $sid = $this->twilio->messageSend($mobile_number, $message);
        if ($sid == 'not send') {
          $this->messenger->addError($this->t('Failed to send SMS.'));
        }
        else {
          $user->set('field_otp_verify', $otp);
          $user->set('field_otp_generate_timestamp', $current_ts);
          $user->save();
          $this->tempstoreMobileNo->set('mobile_number', $mobile_no);
          $this->messenger->addStatus($this->t('One-time passcode has been sent to your mobile number.'));
        }
      }
      else {
        $this->messenger->addError($this->t('Please enter a valid mobile number.'));
      }
    }
    else {
      $this->messenger->addError($this->t('Please enter a valid mobile number.'));
    }
  }


}
