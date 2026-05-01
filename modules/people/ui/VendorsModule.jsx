import React from 'react';
import DirectoryModule from './DirectoryModule';

/**
 * Vendors view of the directory. Prime vendors, MSPs, sub-vendors,
 * referrers, partners — who's in the chain between us and the end client,
 * or who we pay (AP). Backed by the same `companies` table as Clients,
 * filtered to vendor/msp/prime_vendor/sub_vendor/referrer/partner roles.
 */
export default function VendorsModule(props) {
  return <DirectoryModule {...props} mode="vendors" />;
}
