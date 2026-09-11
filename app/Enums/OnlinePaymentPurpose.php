<?php

namespace App\Enums;

enum OnlinePaymentPurpose: string
{
    case TestPayment = 'test_payment';
    case CbtResultChecker = 'cbt_result_checker';
    case AdmissionApplicationFee = 'admission_application_fee';
}
