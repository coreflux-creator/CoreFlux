import React from 'react';
import DirectoryModule from './DirectoryModule';

/**
 * Clients view of the directory. End clients & customers — who you bill,
 * who signs SOWs, who approves time. Backed by the same `companies` table
 * as Vendors, filtered to client/customer roles.
 */
export default function ClientsModule(props) {
  return <DirectoryModule {...props} mode="clients" />;
}
