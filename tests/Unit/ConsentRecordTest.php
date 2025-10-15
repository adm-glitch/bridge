<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\ConsentRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

/**
 * ConsentRecord Model Test
 * 
 * Tests the ConsentRecord model functionality including
 * relationships, scopes, caching, validation, and LGPD compliance.
 */
class ConsentRecordTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test model can be created with valid data.
     *
     * @return void
     */
    public function test_can_create_consent_record()
    {
        $consent = ConsentRecord::create([
            'contact_id' => 123,
            'chatwoot_contact_id' => 456,
            'krayin_lead_id' => 789,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'consent_text' => 'I consent to the processing of my personal data.',
            'consent_version' => '1.0',
        ]);

        $this->assertInstanceOf(ConsentRecord::class, $consent);
        $this->assertEquals(123, $consent->contact_id);
        $this->assertEquals(ConsentRecord::TYPE_DATA_PROCESSING, $consent->consent_type);
        $this->assertEquals(ConsentRecord::STATUS_GRANTED, $consent->status);
    }

    /**
     * Test model fillable attributes.
     *
     * @return void
     */
    public function test_fillable_attributes()
    {
        $consent = new ConsentRecord();
        $fillable = $consent->getFillable();

        $this->assertContains('contact_id', $fillable);
        $this->assertContains('consent_type', $fillable);
        $this->assertContains('status', $fillable);
        $this->assertContains('granted_at', $fillable);
        $this->assertContains('withdrawn_at', $fillable);
        $this->assertContains('ip_address', $fillable);
        $this->assertContains('user_agent', $fillable);
        $this->assertContains('consent_text', $fillable);
        $this->assertContains('consent_version', $fillable);
    }

    /**
     * Test model casts.
     *
     * @return void
     */
    public function test_model_casts()
    {
        $consent = ConsentRecord::create([
            'contact_id' => '123',
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $this->assertIsInt($consent->contact_id);
        $this->assertInstanceOf(\Carbon\Carbon::class, $consent->granted_at);
        $this->assertInstanceOf(\Carbon\Carbon::class, $consent->created_at);
    }

    /**
     * Test consent type validation.
     *
     * @return void
     */
    public function test_consent_type_validation()
    {
        $this->expectException(\InvalidArgumentException::class);

        ConsentRecord::create([
            'contact_id' => 123,
            'consent_type' => 'invalid_type',
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);
    }

    /**
     * Test status validation.
     *
     * @return void
     */
    public function test_status_validation()
    {
        $this->expectException(\InvalidArgumentException::class);

        ConsentRecord::create([
            'contact_id' => 123,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => 'invalid_status',
            'granted_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);
    }

    /**
     * Test granted status requires granted_at timestamp.
     *
     * @return void
     */
    public function test_granted_status_requires_granted_at()
    {
        $this->expectException(\InvalidArgumentException::class);

        ConsentRecord::create([
            'contact_id' => 123,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);
    }

    /**
     * Test withdrawn status requires withdrawn_at timestamp.
     *
     * @return void
     */
    public function test_withdrawn_status_requires_withdrawn_at()
    {
        $this->expectException(\InvalidArgumentException::class);

        ConsentRecord::create([
            'contact_id' => 123,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_WITHDRAWN,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);
    }

    /**
     * Test scope by type.
     *
     * @return void
     */
    public function test_scope_by_type()
    {
        ConsentRecord::create([
            'contact_id' => 123,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        ConsentRecord::create([
            'contact_id' => 124,
            'consent_type' => ConsentRecord::TYPE_MARKETING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $dataProcessingConsents = ConsentRecord::byType(ConsentRecord::TYPE_DATA_PROCESSING)->get();
        $this->assertCount(1, $dataProcessingConsents);
        $this->assertEquals(ConsentRecord::TYPE_DATA_PROCESSING, $dataProcessingConsents->first()->consent_type);
    }

    /**
     * Test scope by status.
     *
     * @return void
     */
    public function test_scope_by_status()
    {
        ConsentRecord::create([
            'contact_id' => 123,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        ConsentRecord::create([
            'contact_id' => 124,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_DENIED,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $grantedConsents = ConsentRecord::byStatus(ConsentRecord::STATUS_GRANTED)->get();
        $this->assertCount(1, $grantedConsents);
        $this->assertEquals(ConsentRecord::STATUS_GRANTED, $grantedConsents->first()->status);
    }

    /**
     * Test scope granted consents.
     *
     * @return void
     */
    public function test_scope_granted()
    {
        ConsentRecord::create([
            'contact_id' => 123,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        ConsentRecord::create([
            'contact_id' => 124,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_DENIED,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $grantedConsents = ConsentRecord::granted()->get();
        $this->assertCount(1, $grantedConsents);
        $this->assertEquals(ConsentRecord::STATUS_GRANTED, $grantedConsents->first()->status);
    }

    /**
     * Test scope valid consents.
     *
     * @return void
     */
    public function test_scope_valid()
    {
        ConsentRecord::create([
            'contact_id' => 123,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        ConsentRecord::create([
            'contact_id' => 124,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_WITHDRAWN,
            'granted_at' => now()->subDays(1),
            'withdrawn_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $validConsents = ConsentRecord::valid()->get();
        $this->assertCount(1, $validConsents);
        $this->assertEquals(ConsentRecord::STATUS_GRANTED, $validConsents->first()->status);
        $this->assertNull($validConsents->first()->withdrawn_at);
    }

    /**
     * Test scope for contact.
     *
     * @return void
     */
    public function test_scope_for_contact()
    {
        ConsentRecord::create([
            'contact_id' => 123,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        ConsentRecord::create([
            'contact_id' => 124,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $contactConsents = ConsentRecord::forContact(123)->get();
        $this->assertCount(1, $contactConsents);
        $this->assertEquals(123, $contactConsents->first()->contact_id);
    }

    /**
     * Test scope by chatwoot contact.
     *
     * @return void
     */
    public function test_scope_by_chatwoot_contact()
    {
        ConsentRecord::create([
            'contact_id' => 123,
            'chatwoot_contact_id' => 456,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $consent = ConsentRecord::byChatwootContact(456)->first();
        $this->assertNotNull($consent);
        $this->assertEquals(456, $consent->chatwoot_contact_id);
    }

    /**
     * Test scope by krayin lead.
     *
     * @return void
     */
    public function test_scope_by_krayin_lead()
    {
        ConsentRecord::create([
            'contact_id' => 123,
            'krayin_lead_id' => 789,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $consent = ConsentRecord::byKrayinLead(789)->first();
        $this->assertNotNull($consent);
        $this->assertEquals(789, $consent->krayin_lead_id);
    }

    /**
     * Test scope by IP address.
     *
     * @return void
     */
    public function test_scope_by_ip_address()
    {
        ConsentRecord::create([
            'contact_id' => 123,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        ConsentRecord::create([
            'contact_id' => 124,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now(),
            'ip_address' => '192.168.1.2',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $consents = ConsentRecord::byIpAddress('192.168.1.1')->get();
        $this->assertCount(1, $consents);
        $this->assertEquals('192.168.1.1', $consents->first()->ip_address);
    }

    /**
     * Test scope by version.
     *
     * @return void
     */
    public function test_scope_by_version()
    {
        ConsentRecord::create([
            'contact_id' => 123,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        ConsentRecord::create([
            'contact_id' => 124,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '2.0',
        ]);

        $consents = ConsentRecord::byVersion('1.0')->get();
        $this->assertCount(1, $consents);
        $this->assertEquals('1.0', $consents->first()->consent_version);
    }

    /**
     * Test is valid method.
     *
     * @return void
     */
    public function test_is_valid()
    {
        $validConsent = ConsentRecord::create([
            'contact_id' => 123,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $withdrawnConsent = ConsentRecord::create([
            'contact_id' => 124,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_WITHDRAWN,
            'granted_at' => now()->subDays(1),
            'withdrawn_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $this->assertTrue($validConsent->isValid());
        $this->assertFalse($withdrawnConsent->isValid());
    }

    /**
     * Test is granted method.
     *
     * @return void
     */
    public function test_is_granted()
    {
        $grantedConsent = ConsentRecord::create([
            'contact_id' => 123,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $deniedConsent = ConsentRecord::create([
            'contact_id' => 124,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_DENIED,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $this->assertTrue($grantedConsent->isGranted());
        $this->assertFalse($deniedConsent->isGranted());
    }

    /**
     * Test is denied method.
     *
     * @return void
     */
    public function test_is_denied()
    {
        $grantedConsent = ConsentRecord::create([
            'contact_id' => 123,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $deniedConsent = ConsentRecord::create([
            'contact_id' => 124,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_DENIED,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $this->assertFalse($grantedConsent->isDenied());
        $this->assertTrue($deniedConsent->isDenied());
    }

    /**
     * Test is withdrawn method.
     *
     * @return void
     */
    public function test_is_withdrawn()
    {
        $grantedConsent = ConsentRecord::create([
            'contact_id' => 123,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $withdrawnConsent = ConsentRecord::create([
            'contact_id' => 124,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_WITHDRAWN,
            'granted_at' => now()->subDays(1),
            'withdrawn_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $this->assertFalse($grantedConsent->isWithdrawn());
        $this->assertTrue($withdrawnConsent->isWithdrawn());
    }

    /**
     * Test is data processing method.
     *
     * @return void
     */
    public function test_is_data_processing()
    {
        $dataProcessingConsent = ConsentRecord::create([
            'contact_id' => 123,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $marketingConsent = ConsentRecord::create([
            'contact_id' => 124,
            'consent_type' => ConsentRecord::TYPE_MARKETING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $this->assertTrue($dataProcessingConsent->isDataProcessing());
        $this->assertFalse($marketingConsent->isDataProcessing());
    }

    /**
     * Test is marketing method.
     *
     * @return void
     */
    public function test_is_marketing()
    {
        $dataProcessingConsent = ConsentRecord::create([
            'contact_id' => 123,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $marketingConsent = ConsentRecord::create([
            'contact_id' => 124,
            'consent_type' => ConsentRecord::TYPE_MARKETING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $this->assertFalse($dataProcessingConsent->isMarketing());
        $this->assertTrue($marketingConsent->isMarketing());
    }

    /**
     * Test is health data method.
     *
     * @return void
     */
    public function test_is_health_data()
    {
        $dataProcessingConsent = ConsentRecord::create([
            'contact_id' => 123,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $healthDataConsent = ConsentRecord::create([
            'contact_id' => 124,
            'consent_type' => ConsentRecord::TYPE_HEALTH_DATA,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $this->assertFalse($dataProcessingConsent->isHealthData());
        $this->assertTrue($healthDataConsent->isHealthData());
    }

    /**
     * Test get consent age days method.
     *
     * @return void
     */
    public function test_get_consent_age_days()
    {
        $consent = ConsentRecord::create([
            'contact_id' => 123,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now()->subDays(5),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $age = $consent->getConsentAgeDays();
        $this->assertEquals(5, $age);
    }

    /**
     * Test get consent age months method.
     *
     * @return void
     */
    public function test_get_consent_age_months()
    {
        $consent = ConsentRecord::create([
            'contact_id' => 123,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now()->subMonths(3),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $age = $consent->getConsentAgeMonths();
        $this->assertEquals(3, $age);
    }

    /**
     * Test get consent age years method.
     *
     * @return void
     */
    public function test_get_consent_age_years()
    {
        $consent = ConsentRecord::create([
            'contact_id' => 123,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now()->subYears(2),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $age = $consent->getConsentAgeYears();
        $this->assertEquals(2, $age);
    }

    /**
     * Test is expired method.
     *
     * @return void
     */
    public function test_is_expired()
    {
        $recentConsent = ConsentRecord::create([
            'contact_id' => 123,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now()->subYears(2),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $oldConsent = ConsentRecord::create([
            'contact_id' => 124,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now()->subYears(6),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $this->assertFalse($recentConsent->isExpired());
        $this->assertTrue($oldConsent->isExpired());
    }

    /**
     * Test get status description method.
     *
     * @return void
     */
    public function test_get_status_description()
    {
        $consent = ConsentRecord::create([
            'contact_id' => 123,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $this->assertEquals('Granted', $consent->getStatusDescription());
    }

    /**
     * Test get type description method.
     *
     * @return void
     */
    public function test_get_type_description()
    {
        $consent = ConsentRecord::create([
            'contact_id' => 123,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $this->assertEquals('Data Processing', $consent->getTypeDescription());
    }

    /**
     * Test get summary method.
     *
     * @return void
     */
    public function test_get_summary()
    {
        $consent = ConsentRecord::create([
            'contact_id' => 123,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now()->subDays(5),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $summary = $consent->getSummary();

        $this->assertIsArray($summary);
        $this->assertEquals(123, $summary['contact_id']);
        $this->assertEquals(ConsentRecord::TYPE_DATA_PROCESSING, $summary['consent_type']);
        $this->assertEquals('Data Processing', $summary['consent_type_description']);
        $this->assertEquals(ConsentRecord::STATUS_GRANTED, $summary['status']);
        $this->assertEquals('Granted', $summary['status_description']);
        $this->assertTrue($summary['is_valid']);
        $this->assertTrue($summary['is_granted']);
        $this->assertFalse($summary['is_denied']);
        $this->assertFalse($summary['is_withdrawn']);
        $this->assertTrue($summary['is_data_processing']);
        $this->assertFalse($summary['is_marketing']);
        $this->assertFalse($summary['is_health_data']);
        $this->assertEquals(5, $summary['consent_age_days']);
        $this->assertFalse($summary['is_expired']);
    }

    /**
     * Test grant consent method.
     *
     * @return void
     */
    public function test_grant_consent()
    {
        $consent = ConsentRecord::grantConsent([
            'contact_id' => 123,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $this->assertInstanceOf(ConsentRecord::class, $consent);
        $this->assertEquals(ConsentRecord::STATUS_GRANTED, $consent->status);
        $this->assertNotNull($consent->granted_at);
    }

    /**
     * Test withdraw consent method.
     *
     * @return void
     */
    public function test_withdraw_consent()
    {
        $consent = ConsentRecord::create([
            'contact_id' => 123,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $result = ConsentRecord::withdrawConsent($consent->id);

        $this->assertTrue($result);
        $this->assertEquals(ConsentRecord::STATUS_WITHDRAWN, $consent->fresh()->status);
        $this->assertNotNull($consent->fresh()->withdrawn_at);
    }

    /**
     * Test create or update consent method.
     *
     * @return void
     */
    public function test_create_or_update_consent()
    {
        // Test creation
        $consent = ConsentRecord::createOrUpdateConsent([
            'contact_id' => 123,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_GRANTED,
            'granted_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $this->assertInstanceOf(ConsentRecord::class, $consent);
        $this->assertEquals(123, $consent->contact_id);

        // Test update
        $updatedConsent = ConsentRecord::createOrUpdateConsent([
            'contact_id' => 123,
            'consent_type' => ConsentRecord::TYPE_DATA_PROCESSING,
            'status' => ConsentRecord::STATUS_WITHDRAWN,
            'granted_at' => now()->subDays(1),
            'withdrawn_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'consent_text' => 'Test consent',
            'consent_version' => '1.0',
        ]);

        $this->assertEquals(ConsentRecord::STATUS_WITHDRAWN, $updatedConsent->status);
        $this->assertEquals($consent->id, $updatedConsent->id);
    }

    /**
     * Test get valid consent types method.
     *
     * @return void
     */
    public function test_get_valid_consent_types()
    {
        $types = ConsentRecord::getValidConsentTypes();

        $this->assertContains(ConsentRecord::TYPE_DATA_PROCESSING, $types);
        $this->assertContains(ConsentRecord::TYPE_MARKETING, $types);
        $this->assertContains(ConsentRecord::TYPE_HEALTH_DATA, $types);
    }

    /**
     * Test get valid statuses method.
     *
     * @return void
     */
    public function test_get_valid_statuses()
    {
        $statuses = ConsentRecord::getValidStatuses();

        $this->assertContains(ConsentRecord::STATUS_GRANTED, $statuses);
        $this->assertContains(ConsentRecord::STATUS_DENIED, $statuses);
        $this->assertContains(ConsentRecord::STATUS_WITHDRAWN, $statuses);
    }

    /**
     * Test get consent type descriptions method.
     *
     * @return void
     */
    public function test_get_consent_type_descriptions()
    {
        $descriptions = ConsentRecord::getConsentTypeDescriptions();

        $this->assertEquals('Data Processing', $descriptions[ConsentRecord::TYPE_DATA_PROCESSING]);
        $this->assertEquals('Marketing', $descriptions[ConsentRecord::TYPE_MARKETING]);
        $this->assertEquals('Health Data', $descriptions[ConsentRecord::TYPE_HEALTH_DATA]);
    }

    /**
     * Test get status descriptions method.
     *
     * @return void
     */
    public function test_get_status_descriptions()
    {
        $descriptions = ConsentRecord::getStatusDescriptions();

        $this->assertEquals('Granted', $descriptions[ConsentRecord::STATUS_GRANTED]);
        $this->assertEquals('Denied', $descriptions[ConsentRecord::STATUS_DENIED]);
        $this->assertEquals('Withdrawn', $descriptions[ConsentRecord::STATUS_WITHDRAWN]);
    }
}
