import axios, { type AxiosInstance } from 'axios';

export const api: AxiosInstance = axios.create({
    timeout: 60000,
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
    },
});
