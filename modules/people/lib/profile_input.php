<?php
/** Explicit person-editor boundary. Identity links and private storage use their own workflows. */
declare(strict_types=1);

function peopleProfileEditableFields(): array
{
    return ['first_name','middle_name','last_name','preferred_name','email_primary','email_secondary',
        'phone_primary','phone_secondary','classification','status','external_id','source','linkedin_url',
        'recruiter_notes','work_auth_status','work_auth_expiry','requires_sponsorship',
        'employment_type','hire_date','termination_date','pay_frequency','referred_by_person_id','entity_id',
        ...peopleProfilePrivateFields()];
}

function peopleProfilePrivateFields(): array
{
    return ['dob','ssn_last4','gender','marital_status','home_address_line1','home_address_line2',
        'home_city','home_state','home_postal_code','home_country','mailing_address_line1',
        'mailing_address_line2','mailing_city','mailing_state','mailing_postal_code','mailing_country'];
}

function peopleProfileInput(array $body): array
{
    $unknown=array_diff(array_keys($body),peopleProfileEditableFields());
    if ($unknown) throw new InvalidArgumentException('One or more fields require a separate identity, document or system workflow.');
    foreach ($body as $value) if ($value!==null && !is_scalar($value)) {
        throw new InvalidArgumentException('Person profile fields must be scalar values or explicit nulls.');
    }
    return $body;
}
