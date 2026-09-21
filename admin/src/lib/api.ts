import axios from 'axios';
import type { ThaiNexusShippingService } from './types';

function requestOptions() {
    return {
        headers: {
            'X-WP-Nonce': (window as Window & { tnxlData?: { nonce?: string } }).tnxlData?.nonce || '',
        },
    };
}

function apiUrl(): string {
    return (window as Window & { tnxlData?: { apiUrl?: string } }).tnxlData?.apiUrl || '';
}

export async function fetchShippingServices(): Promise<ThaiNexusShippingService[]> {
    const response = await axios.get(apiUrl() + '/shipping-services', requestOptions());
    return response.data?.services || [];
}

export async function fetchSettings(): Promise<Record<string, any>> {
    const response = await axios.get(apiUrl() + '/settings', requestOptions());
    return response.data;
}

export async function saveSettings(body: Record<string, unknown>) {
    const response = await axios.post(apiUrl() + '/settings', body, requestOptions());
    return response.data;
}
