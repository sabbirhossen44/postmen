<?php

namespace Tests\Unit;

use App\Services\AwsSigner;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * IAM ListUsers vector, verified against botocore's SigV4Auth
 * (canonical request hash 5599feec…, string-to-sign as below).
 */
class AwsSignerTest extends TestCase
{
    public function test_signs_aws_documentation_example(): void
    {
        $headers = AwsSigner::sign(
            'GET',
            'https://iam.amazonaws.com/?Action=ListUsers&Version=2010-05-08',
            [],
            '',
            'AKIDEXAMPLE',
            'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
            'us-east-1',
            'iam',
            new DateTimeImmutable('2015-08-30 12:36:00', new DateTimeZone('UTC')),
        );

        $this->assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/iam/aws4_request, '
            .'SignedHeaders=host;x-amz-date, '
            .'Signature=b2e4af44cfad96d9ffa3c5653674a927b9b0995c33de22e1f843745ce37c1d5e',
            $headers['Authorization'],
        );
        $this->assertSame('20150830T123600Z', $headers['X-Amz-Date']);
    }
}
