<?php

use App\Payments\Paytm\PaytmChecksum;

/*
 * The signatures below were made by Paytm's official paytmchecksum package (Python, 1.7) with the same key.
 */
const PAYTM_TEST_KEY = 'abcd1234ABCD!@#$';
const PAYTM_TEST_BODY = '{"mid":"TESTMID","orderId":"ORD1"}';
const PAYTM_OFFICIAL_BODY_SIGNATURE = 'OMEOEVYmnFMdBpdzRo3dAiiXTI7UN/FswjftvxmySOISUzbr6Vg+cH+ka6rjUvMNEQWYmMg06JOjamiIAXX+ow1OMZJMFOUw3vpXKd4Qo60=';
const PAYTM_OFFICIAL_FIELDS_SIGNATURE = '+Y32mrcQWuWk6ZoHbyaohupQ35+owNef9zFrmRTYYe4rA8G33vzgClYo+sOzR1RiJbW4fT+9U3npXSfBifZ4kLbJJek0LROR9LqMSLAtO1o=';

it('verifies a signature made by Paytm\'s own library', function () {
    expect(PaytmChecksum::verify(PAYTM_TEST_BODY, PAYTM_TEST_KEY, PAYTM_OFFICIAL_BODY_SIGNATURE))->toBeTrue();
});

it('signs so that its own check passes, and refuses a changed body or another key', function () {
    $signature = PaytmChecksum::sign(PAYTM_TEST_BODY, PAYTM_TEST_KEY);

    expect(PaytmChecksum::verify(PAYTM_TEST_BODY, PAYTM_TEST_KEY, $signature))->toBeTrue()
        ->and($signature)->not->toBe(PaytmChecksum::sign(PAYTM_TEST_BODY, PAYTM_TEST_KEY))
        ->and(PaytmChecksum::verify('{"mid":"TESTMID","orderId":"ORD2"}', PAYTM_TEST_KEY, $signature))->toBeFalse()
        ->and(PaytmChecksum::verify(PAYTM_TEST_BODY, 'abcd1234ABCD!@#%', $signature))->toBeFalse()
        ->and(PaytmChecksum::verify(PAYTM_TEST_BODY, PAYTM_TEST_KEY, 'not base64 at all'))->toBeFalse();
});

it('checks posted fields sorted by name, as Paytm signs its callbacks', function () {
    $fields = ['STATUS' => 'TXN_SUCCESS', 'TXNAMOUNT' => '1.00', 'MID' => 'TESTMID', 'ORDERID' => 'ORD1'];

    expect(PaytmChecksum::verifyFields($fields, PAYTM_TEST_KEY, PAYTM_OFFICIAL_FIELDS_SIGNATURE))->toBeTrue()
        ->and(PaytmChecksum::verifyFields([...$fields, 'TXNAMOUNT' => '100.00'], PAYTM_TEST_KEY, PAYTM_OFFICIAL_FIELDS_SIGNATURE))->toBeFalse()
        ->and(PaytmChecksum::verifyFields($fields, PAYTM_TEST_KEY, PaytmChecksum::signFields($fields, PAYTM_TEST_KEY)))->toBeTrue();
});
