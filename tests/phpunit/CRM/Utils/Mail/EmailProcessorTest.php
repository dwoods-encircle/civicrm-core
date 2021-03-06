<?php

/**
 * Class CRM_Utils_Mail_EmailProcessorTest
 * @group headless
 */
class CRM_Utils_Mail_EmailProcessorTest extends CiviUnitTestCase {

  /**
   * Event queue record.
   *
   * @var array
   */
  protected $eventQueue = [];

  /**
   * ID of our sample contact.
   *
   * @var int
   */
  protected $contactID;

  public function setUp() {
    parent::setUp();
    CRM_Utils_File::cleanDir(__DIR__ . '/data/mail');
    mkdir(__DIR__ . '/data/mail');
    $this->callAPISuccess('MailSettings', 'get', [
      'api.MailSettings.create' => [
        'name' => 'local',
        'protocol' => 'Localdir',
        'source' => __DIR__ . '/data/mail',
        'domain' => 'example.com',
      ],
    ]);
  }

  public function tearDown() {
    CRM_Utils_File::cleanDir(__DIR__ . '/data/mail');
    parent::tearDown();
    $this->quickCleanup([
      'civicrm_group',
      'civicrm_group_contact',
      'civicrm_mailing',
      'civicrm_mailing_job',
      'civicrm_mailing_event_bounce',
      'civicrm_mailing_event_queue',
      'civicrm_mailing_group',
      'civicrm_mailing_recipients',
      'civicrm_contact',
      'civicrm_email',
      'civicrm_activity',
    ]);
  }

  /**
   * Test the job processing function works and processes a bounce.
   */
  public function testBounceProcessing() {
    $this->setUpMailing();

    copy(__DIR__ . '/data/bounces/bounce_no_verp.txt', __DIR__ . '/data/mail/bounce_no_verp.txt');
    $this->assertTrue(file_exists(__DIR__ . '/data/mail/bounce_no_verp.txt'));
    $this->callAPISuccess('job', 'fetch_bounces', []);
    $this->assertFalse(file_exists(__DIR__ . '/data/mail/bounce_no_verp.txt'));
    $this->checkMailingBounces(1);
  }

  /**
   * Test the job processing function can handle invalid characters.
   */
  public function testBounceProcessingInvalidCharacter() {
    $this->setUpMailing();
    $mail = 'test_invalid_character.eml';

    copy(__DIR__ . '/data/bounces/' . $mail, __DIR__ . '/data/mail/' . $mail);
    $this->callAPISuccess('job', 'fetch_bounces', []);
    $this->assertFalse(file_exists(__DIR__ . '/data/mail/' . $mail));
    $this->checkMailingBounces(1);
  }

  /**
   * Test that the job processing function can handle incoming utf8mb4 characters.
   */
  public function testBounceProcessingUTF8mb4() {
    $this->setUpMailing();
    $mail = 'test_utf8mb4_character.txt';

    copy(__DIR__ . '/data/bounces/' . $mail, __DIR__ . '/data/mail/' . $mail);
    $this->callAPISuccess('job', 'fetch_bounces', []);
    $this->assertFalse(file_exists(__DIR__ . '/data/mail/' . $mail));
    $this->checkMailingBounces(1);
  }

  /**
   * Tests that a multipart related email does not cause pain & misery & fatal errors.
   *
   * Sample taken from https://www.phpclasses.org/browse/file/14672.html
   */
  public function testProcessingMultipartRelatedEmail() {
    $this->setUpMailing();
    $mail = 'test_sample_message.eml';

    copy(__DIR__ . '/data/bounces/' . $mail, __DIR__ . '/data/mail/' . $mail);
    $this->callAPISuccess('job', 'fetch_bounces', []);
    $this->assertFalse(file_exists(__DIR__ . '/data/mail/' . $mail));
    $this->checkMailingBounces(1);
  }

  /**
   * Tests that a nested multipart email does not cause pain & misery & fatal errors.
   *
   * Sample anonymized from an email that broke bounce processing at Wikimedia
   */
  public function testProcessingNestedMultipartEmail() {
    $this->setUpMailing();
    $mail = 'test_nested_message.eml';

    copy(__DIR__ . '/data/bounces/' . $mail, __DIR__ . '/data/mail/' . $mail);
    $this->callAPISuccess('job', 'fetch_bounces', []);
    $this->assertFalse(file_exists(__DIR__ . '/data/mail/' . $mail));
    $this->checkMailingBounces(1);
  }

  /**
   * Test that a deleted email does not cause a hard fail.
   *
   * The civicrm_mailing_event_queue table tracks email ids to represent an
   * email address. The id may not represent the same email by the time the bounce may
   * come in - a weakness of storing the id not the email. Relevant here
   * is that it might have been deleted altogether, in which case the bounce should be
   * silently ignored. This ignoring is also at the expense of the contact
   * having the same email address with a different id.
   *
   * Longer term it would make sense to track the email address & track bounces back to that
   * rather than an id that may not reflect the email used. Issue logged CRM-20021.
   *
   * For not however, we are testing absence of mysql error in conjunction with CRM-20016.
   */
  public function testBounceProcessingDeletedEmail() {
    $this->setUpMailing();
    $this->callAPISuccess('Email', 'get', [
      'contact_id' => $this->contactID,
      'api.email.delete' => 1,
    ]);

    copy(__DIR__ . '/data/bounces/bounce_no_verp.txt', __DIR__ . '/data/mail/bounce_no_verp.txt');
    $this->assertTrue(file_exists(__DIR__ . '/data/mail/bounce_no_verp.txt'));
    $this->callAPISuccess('job', 'fetch_bounces', []);
    $this->assertFalse(file_exists(__DIR__ . '/data/mail/bounce_no_verp.txt'));
    $this->checkMailingBounces(1);
  }

  /**
   * Wrapper to check for mailing bounces.
   *
   * Normally we would call $this->callAPISuccessGetCount but there is not one & there is resistance to
   * adding apis for 'convenience' so just adding a hacky function to get past the impasse.
   *
   * @param int $expectedCount
   */
  public function checkMailingBounces($expectedCount) {
    $this->assertEquals($expectedCount, CRM_Core_DAO::singleValueQuery(
      "SELECT count(*) FROM civicrm_mailing_event_bounce"
    ));
  }

  /**
   * Set up a mailing.
   */
  public function setUpMailing() {
    $this->contactID = $this->individualCreate(['email' => 'undeliverable@example.com']);
    $groupID = $this->callAPISuccess('Group', 'create', [
      'title' => 'Mailing group',
      'api.GroupContact.create' => [
        'contact_id' => $this->contactID,
      ],
    ])['id'];
    $this->createMailing(['scheduled_date' => 'now', 'groups' => ['include' => [$groupID]]]);
    $this->callAPISuccess('job', 'process_mailing', []);
    $this->eventQueue = $this->callAPISuccess('MailingEventQueue', 'get', ['api.MailingEventQueue.create' => ['hash' => 'aaaaaaaaaaaaaaaa']]);
  }

  /**
   * Set up mail account with 'Skip emails which do not have a Case ID or
   * Case hash' option enabled.
   */
  public function setUpSkipNonCasesEmail() {
    $this->callAPISuccess('MailSettings', 'get', [
      'api.MailSettings.create' => [
        'name' => 'mailbox',
        'protocol' => 'Localdir',
        'source' => __DIR__ . '/data/mail',
        'domain' => 'example.com',
        'is_default' => '0',
        'is_non_case_email_skipped' => TRUE,
      ],
    ]);
  }

  /**
   * Test case email processing when is_non_case_email_skipped is enabled.
   */
  public function testInboundProcessingCaseEmail() {
    $this->setUpSkipNonCasesEmail();
    $mail = 'test_cases_email.eml';

    copy(__DIR__ . '/data/inbound/' . $mail, __DIR__ . '/data/mail/' . $mail);
    $this->callAPISuccess('job', 'fetch_activities', []);
    $result = civicrm_api3('Activity', 'get', [
      'sequential' => 1,
      'subject' => ['LIKE' => "%[case #214bf6d]%"],
    ]);
    $this->assertNotEmpty($result['values'][0]['id']);
  }

  /**
   * Test non case email processing when is_non_case_email_skipped is enabled.
   */
  public function testInboundProcessingNonCaseEmail() {
    $this->setUpSkipNonCasesEmail();
    $mail = 'test_non_cases_email.eml';

    copy(__DIR__ . '/data/inbound/' . $mail, __DIR__ . '/data/mail/' . $mail);
    $this->callAPISuccess('job', 'fetch_activities', []);
    $result = civicrm_api3('Activity', 'get', [
      'sequential' => 1,
      'subject' => ['LIKE' => "%Love letter%"],
    ]);
    $this->assertEmpty($result['values']);
  }

  /**
   * Set up mail account with 'Do not create new contacts when filing emails'
   * option enabled.
   */
  public function setUpDoNotCreateContact() {
    $this->callAPISuccess('MailSettings', 'get', [
      'api.MailSettings.create' => [
        'name' => 'mailbox',
        'protocol' => 'Localdir',
        'source' => __DIR__ . '/data/mail',
        'domain' => 'example.com',
        'is_default' => '0',
        'is_contact_creation_disabled_if_no_match' => TRUE,
      ],
    ]);
  }

  /**
   * Test case email processing when is_non_case_email_skipped is enabled.
   */
  public function testInboundProcessingDoNotCreateContact() {
    $this->setUpDoNotCreateContact();
    $mail = 'test_non_cases_email.eml';

    copy(__DIR__ . '/data/inbound/' . $mail, __DIR__ . '/data/mail/' . $mail);
    $this->callAPISuccess('job', 'fetch_activities', []);
    $result = civicrm_api3('Contact', 'get', [
      'sequential' => 1,
      'email' => "from@test.test",
    ]);
    $this->assertEmpty($result['values']);
  }

}
