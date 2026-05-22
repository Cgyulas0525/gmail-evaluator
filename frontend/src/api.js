const configuredApiUrl = import.meta.env.VITE_API_URL || '';
export const API_BASE = configuredApiUrl || `${window.location.origin}/api`;
export const APP_ORIGIN = API_BASE.replace(/\/api\/?$/, '');

let unauthorizedHandler = null;

export function setUnauthorizedHandler(handler) {
  unauthorizedHandler = handler;
}

function getCookie(name) {
  const match = document.cookie.match(new RegExp(`(^|; )${name}=([^;]*)`));
  return match ? decodeURIComponent(match[2]) : null;
}

export async function ensureCsrf() {
  await fetch(`${APP_ORIGIN}/sanctum/csrf-cookie`, {
    credentials: 'include',
  });
}

export async function apiFetch(path, options = {}) {
  const url = path.startsWith('http') ? path : `${API_BASE}${path.startsWith('/') ? path : `/${path}`}`;
  const method = (options.method || 'GET').toUpperCase();
  const headers = {
    Accept: 'application/json',
    ...(options.headers || {}),
  };

  if (method !== 'GET' && method !== 'HEAD') {
    const token = getCookie('XSRF-TOKEN');
    if (token) {
      headers['X-XSRF-TOKEN'] = token;
    }
  }

  let body = options.body;
  if (body && typeof body === 'object' && !(body instanceof FormData)) {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(body);
  }

  const response = await fetch(url, {
    ...options,
    method,
    headers,
    body,
    credentials: 'include',
  });

  if (response.status === 401 && unauthorizedHandler) {
    unauthorizedHandler();
  }

  return response;
}

function parseAuthError(data, fallback) {
  if (data.errors) {
    const firstKey = Object.keys(data.errors)[0];
    if (firstKey && data.errors[firstKey]?.[0]) {
      return data.errors[firstKey][0];
    }
  }

  return data.message || fallback;
}

async function authPost(path, payload) {
  await ensureCsrf();
  const response = await fetch(`${APP_ORIGIN}${path}`, {
    method: 'POST',
    credentials: 'include',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-XSRF-TOKEN': getCookie('XSRF-TOKEN') || '',
    },
    body: JSON.stringify(payload),
  });

  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new Error(parseAuthError(data, 'A művelet sikertelen.'));
  }

  return data.user;
}

export const authApi = {
  async user() {
    const response = await apiFetch('/user');
    if (!response.ok) {
      throw new Error('Unauthenticated');
    }
    return response.json();
  },

  async login(credentials) {
    return authPost('/login', credentials);
  },

  async register(payload) {
    return authPost('/register', payload);
  },

  async logout() {
    await ensureCsrf();
    await fetch(`${APP_ORIGIN}/logout`, {
      method: 'POST',
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        'X-XSRF-TOKEN': getCookie('XSRF-TOKEN') || '',
      },
    });
  },
};
