import axios, { type AxiosInstance } from 'axios';

export const api: AxiosInstance = axios.create({
  timeout: 15000,
  headers: {
    'X-Requested-With': 'XMLHttpRequest',
  },
});


