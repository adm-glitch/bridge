<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\ContactMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * ContactMapping Model Test
 * 
 * Tests the ContactMapping model functionality including
 * relationships, scopes, caching, and validation.
 */
class ContactMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test model can be created with valid data.
     *
     * @return void
     */
    public function test_can_create_contact_mapping()
    {
        $mapping = ContactMapping::create([
            'chatwoot_contact_id' => 123,
            'krayin_lead_id' => 456,
            'krayin_person_id' => null,
        ]);

        $this->assertInstanceOf(ContactMapping::class, $mapping);
        $this->assertEquals(123, $mapping->chatwoot_contact_id);
        $this->assertEquals(456, $mapping->krayin_lead_id);
        $this->assertNull($mapping->krayin_person_id);
    }

    /**
     * Test model fillable attributes.
     *
     * @return void
     */
    public function test_fillable_attributes()
    {
        $mapping = new ContactMapping();
        $fillable = $mapping->getFillable();

        $this->assertContains('chatwoot_contact_id', $fillable);
        $this->assertContains('krayin_lead_id', $fillable);
        $this->assertContains('krayin_person_id', $fillable);
    }

    /**
     * Test model casts.
     *
     * @return void
     */
    public function test_model_casts()
    {
        $mapping = ContactMapping::create([
            'chatwoot_contact_id' => '123',
            'krayin_lead_id' => '456',
        ]);

        $this->assertIsInt($mapping->chatwoot_contact_id);
        $this->assertIsInt($mapping->krayin_lead_id);
        $this->assertInstanceOf(\Carbon\Carbon::class, $mapping->created_at);
    }

    /**
     * Test scope by chatwoot contact.
     *
     * @return void
     */
    public function test_scope_by_chatwoot_contact()
    {
        ContactMapping::create([
            'chatwoot_contact_id' => 123,
            'krayin_lead_id' => 456,
        ]);

        $mapping = ContactMapping::byChatwootContact(123)->first();

        $this->assertNotNull($mapping);
        $this->assertEquals(123, $mapping->chatwoot_contact_id);
    }

    /**
     * Test scope by krayin lead.
     *
     * @return void
     */
    public function test_scope_by_krayin_lead()
    {
        ContactMapping::create([
            'chatwoot_contact_id' => 123,
            'krayin_lead_id' => 456,
        ]);

        $mapping = ContactMapping::byKrayinLead(456)->first();

        $this->assertNotNull($mapping);
        $this->assertEquals(456, $mapping->krayin_lead_id);
    }

    /**
     * Test scope with leads.
     *
     * @return void
     */
    public function test_scope_with_leads()
    {
        ContactMapping::create([
            'chatwoot_contact_id' => 123,
            'krayin_lead_id' => 456,
        ]);

        ContactMapping::create([
            'chatwoot_contact_id' => 124,
            'krayin_person_id' => 789,
        ]);

        $mappingsWithLeads = ContactMapping::withLeads()->get();

        $this->assertCount(1, $mappingsWithLeads);
        $this->assertEquals(456, $mappingsWithLeads->first()->krayin_lead_id);
    }

    /**
     * Test has krayin lead method.
     *
     * @return void
     */
    public function test_has_krayin_lead()
    {
        $mappingWithLead = ContactMapping::create([
            'chatwoot_contact_id' => 123,
            'krayin_lead_id' => 456,
        ]);

        $mappingWithoutLead = ContactMapping::create([
            'chatwoot_contact_id' => 124,
            'krayin_person_id' => 789,
        ]);

        $this->assertTrue($mappingWithLead->hasKrayinLead());
        $this->assertFalse($mappingWithoutLead->hasKrayinLead());
    }

    /**
     * Test has krayin person method.
     *
     * @return void
     */
    public function test_has_krayin_person()
    {
        $mappingWithPerson = ContactMapping::create([
            'chatwoot_contact_id' => 123,
            'krayin_person_id' => 789,
        ]);

        $mappingWithoutPerson = ContactMapping::create([
            'chatwoot_contact_id' => 124,
            'krayin_lead_id' => 456,
        ]);

        $this->assertTrue($mappingWithPerson->hasKrayinPerson());
        $this->assertFalse($mappingWithoutPerson->hasKrayinPerson());
    }

    /**
     * Test get primary krayin id method.
     *
     * @return void
     */
    public function test_get_primary_krayin_id()
    {
        $mappingWithLead = ContactMapping::create([
            'chatwoot_contact_id' => 123,
            'krayin_lead_id' => 456,
        ]);

        $mappingWithPerson = ContactMapping::create([
            'chatwoot_contact_id' => 124,
            'krayin_person_id' => 789,
        ]);

        $this->assertEquals(456, $mappingWithLead->getPrimaryKrayinId());
        $this->assertEquals(789, $mappingWithPerson->getPrimaryKrayinId());
    }

    /**
     * Test get primary krayin type method.
     *
     * @return void
     */
    public function test_get_primary_krayin_type()
    {
        $mappingWithLead = ContactMapping::create([
            'chatwoot_contact_id' => 123,
            'krayin_lead_id' => 456,
        ]);

        $mappingWithPerson = ContactMapping::create([
            'chatwoot_contact_id' => 124,
            'krayin_person_id' => 789,
        ]);

        $this->assertEquals('lead', $mappingWithLead->getPrimaryKrayinType());
        $this->assertEquals('person', $mappingWithPerson->getPrimaryKrayinType());
    }

    /**
     * Test create or update mapping method.
     *
     * @return void
     */
    public function test_create_or_update_mapping()
    {
        // Test creation
        $mapping = ContactMapping::createOrUpdateMapping([
            'chatwoot_contact_id' => 123,
            'krayin_lead_id' => 456,
        ]);

        $this->assertInstanceOf(ContactMapping::class, $mapping);
        $this->assertEquals(123, $mapping->chatwoot_contact_id);

        // Test update
        $updatedMapping = ContactMapping::createOrUpdateMapping([
            'chatwoot_contact_id' => 123,
            'krayin_lead_id' => 789,
        ]);

        $this->assertEquals(789, $updatedMapping->krayin_lead_id);
        $this->assertEquals($mapping->id, $updatedMapping->id);
    }
}
