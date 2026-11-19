<?php

namespace JustinHoltWeb\FreeLink\Tests\Unit;

use JustinHoltWeb\FreeLink\Links\Custom;
use JustinHoltWeb\FreeLink\Links\Email;
use JustinHoltWeb\FreeLink\Links\Phone;
use JustinHoltWeb\FreeLink\Links\Sms;
use JustinHoltWeb\FreeLink\Links\Url;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the simple (non-element) link types and the shared Link behaviour.
 */
class LinkTest extends TestCase
{
    public function test_url_returns_value_as_url(): void
    {
        $link = new Url;
        $link->type = 'url';
        $link->value = 'https://example.com';

        self::assertSame('https://example.com', $link->getUrl());
        self::assertFalse($link->isEmpty());
        self::assertFalse($link->isElement());
    }

    public function test_url_appends_suffix(): void
    {
        $link = new Url;
        $link->value = 'https://example.com';
        $link->urlSuffix = '#pricing';

        self::assertSame('https://example.com#pricing', $link->getUrl());
    }

    public function test_empty_link_returns_null_url(): void
    {
        $link = new Url;

        self::assertTrue($link->isEmpty());
        self::assertNull($link->getUrl());
        self::assertSame('', (string) $link);
    }

    public function test_text_falls_back_to_url_then_label(): void
    {
        $link = new Url;
        $link->value = 'https://example.com';
        self::assertSame('https://example.com', $link->getText());

        $link->label = 'Visit Example';
        self::assertSame('Visit Example', $link->getText());
    }

    public function test_target_reflects_new_window(): void
    {
        $link = new Url;
        $link->value = 'https://example.com';

        self::assertNull($link->getTarget());

        $link->newWindow = true;
        self::assertSame('_blank', $link->getTarget());
    }

    public function test_email_produces_mailto_url(): void
    {
        $link = new Email;
        $link->type = 'email';
        $link->value = 'hello@example.com';

        self::assertSame('mailto:hello@example.com', $link->getUrl());
        self::assertSame('hello@example.com', $link->getText());
    }

    public function test_email_encodes_subject_into_mailto_url(): void
    {
        $link = new Email;
        $link->type = 'email';
        $link->value = 'hello@example.com';
        $link->subject = 'Hi there & welcome';

        self::assertSame(
            'mailto:hello@example.com?subject=Hi%20there%20%26%20welcome',
            $link->getUrl(),
        );
    }

    public function test_email_without_subject_has_plain_mailto_url(): void
    {
        $link = new Email;
        $link->type = 'email';
        $link->value = 'hello@example.com';

        // Empty string should be treated the same as no subject.
        $link->subject = '';
        self::assertSame('mailto:hello@example.com', $link->getUrl());
    }

    public function test_email_subject_round_trips_through_arrays(): void
    {
        $link = new Email;
        $link->type = 'email';
        $link->value = 'hello@example.com';
        $link->subject = 'Quote request';

        self::assertSame('Quote request', $link->toArray()['subject']);
        self::assertSame('Quote request', $link->toApiArray()['subject']);
    }

    public function test_phone_strips_formatting_for_tel_uri(): void
    {
        $link = new Phone;
        $link->type = 'phone';
        $link->value = '+1 (555) 123-4567';

        self::assertSame('tel:+15551234567', $link->getUrl());
        self::assertSame('+1 (555) 123-4567', $link->getText());
    }

    public function test_sms_produces_sms_uri(): void
    {
        $link = new Sms;
        $link->type = 'sms';
        $link->value = '555-1234';

        self::assertSame('sms:5551234', $link->getUrl());
    }

    public function test_custom_value_passes_through_unchanged(): void
    {
        $link = new Custom;
        $link->type = 'custom';
        $link->value = 'javascript:void(0)';

        self::assertSame('javascript:void(0)', $link->getUrl());
    }

    public function test_get_link_renders_anchor_tag(): void
    {
        $link = new Url;
        $link->type = 'url';
        $link->value = 'https://example.com';
        $link->label = 'Example';

        $html = (string) $link->getLink();

        self::assertStringContainsString('<a ', $html);
        self::assertStringContainsString('href="https://example.com"', $html);
        self::assertStringContainsString('>Example</a>', $html);
    }

    public function test_get_link_adds_noopener_for_new_window(): void
    {
        $link = new Url;
        $link->value = 'https://example.com';
        $link->newWindow = true;

        $html = (string) $link->getLink();

        self::assertStringContainsString('target="_blank"', $html);
        self::assertStringContainsString('noopener', $html);
        self::assertStringContainsString('noreferrer', $html);
    }

    public function test_get_link_merges_passed_attributes_and_custom_attributes(): void
    {
        $link = new Url;
        $link->value = 'https://example.com';
        $link->classes = 'btn';
        $link->customAttributes = [
            ['attribute' => 'data-track', 'value' => 'cta'],
        ];

        $html = (string) $link->getLink(['id' => 'main-cta']);

        self::assertStringContainsString('class="btn"', $html);
        self::assertStringContainsString('data-track="cta"', $html);
        self::assertStringContainsString('id="main-cta"', $html);
    }

    public function test_get_link_encodes_text(): void
    {
        $link = new Custom;
        $link->value = 'https://example.com';
        $link->label = '<script>alert(1)</script>';

        $html = (string) $link->getLink();

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_to_array_returns_raw_storage_data(): void
    {
        $link = new Url;
        $link->type = 'url';
        $link->value = 'https://example.com';
        $link->label = 'Example';
        $link->newWindow = true;

        $data = $link->toArray();

        self::assertSame('url', $data['type']);
        self::assertSame('https://example.com', $data['value']);
        self::assertSame('Example', $data['label']);
        self::assertTrue($data['newWindow']);
        self::assertArrayHasKey('customAttributes', $data);
    }

    public function test_to_api_array_returns_resolved_values(): void
    {
        $link = new Email;
        $link->type = 'email';
        $link->value = 'hello@example.com';
        $link->newWindow = true;

        $api = $link->toApiArray();

        self::assertSame('email', $api['type']);
        self::assertSame('mailto:hello@example.com', $api['url']);
        self::assertSame('hello@example.com', $api['text']);
        self::assertSame('_blank', $api['target']);
        self::assertFalse($api['isElement']);
        self::assertFalse($api['isEmpty']);
    }

    public function test_json_serialize_matches_api_array(): void
    {
        $link = new Url;
        $link->type = 'url';
        $link->value = 'https://example.com';

        self::assertSame($link->toApiArray(), $link->jsonSerialize());

        $decoded = json_decode(json_encode($link), true);
        self::assertSame('https://example.com', $decoded['url']);
    }

    public function test_handles_and_display_names(): void
    {
        self::assertSame('url', Url::handle());
        self::assertSame('email', Email::handle());
        self::assertSame('phone', Phone::handle());
        self::assertSame('sms', Sms::handle());
        self::assertSame('custom', Custom::handle());

        self::assertSame('URL', Url::displayName());
        self::assertSame('SMS', Sms::displayName());
    }
}
