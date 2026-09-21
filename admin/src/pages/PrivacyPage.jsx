import { Shield } from 'lucide-react';

export default function PrivacyPage() {
  return (
    <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
      <div className="bg-primary p-5 flex items-center gap-3">
        <Shield className="text-white w-6 h-6" />
        <h2 className="text-lg font-bold text-white">Privacy & Data Disclosure</h2>
      </div>
      <div className="p-8 space-y-4 text-gray-700 leading-relaxed text-sm">
        <p>
          To provide real-time shipping quotations, this plugin securely transmits package dimensions,
          weights, and the <strong>customer&apos;s shipping address</strong> (Country, City, State, and Postal Code)
          to the{' '}
          <a href="https://app.thainexus.co.th/" target="_blank" rel="noreferrer" className="text-primary hover:underline font-medium">
            Thai Nexus API
          </a>.
        </p>
        <p>
          Store configuration (shipper address, commission rules, box definitions, and API token) is
          stored in your WordPress database. Only administrators can read or update this data.
        </p>
        <p>
          It communicates with the{' '}
          <a href="https://frankfurter.dev/" target="_blank" rel="noreferrer" className="text-primary hover:underline font-medium">
            Frankfurter API
          </a>{' '}
          for exchange rates, and falls back to{' '}
          <a href="https://www.exchangerate-api.com" target="_blank" rel="noreferrer" className="text-primary hover:underline font-medium">
            Exchange Rate API
          </a>{' '}
          when needed. No customer PII (Name, Email, Phone) is sent during the quotation phase.
        </p>
      </div>
    </div>
  );
}
