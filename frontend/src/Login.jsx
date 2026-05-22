import React, { useState } from 'react';
import { authApi } from './api.js';

export default function Login({ onLogin }) {
  const [mode, setMode] = useState('login');
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [remember, setRemember] = useState(false);
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const switchMode = (nextMode) => {
    setMode(nextMode);
    setError('');
    setPassword('');
    setPasswordConfirmation('');
  };

  const handleSubmit = async (event) => {
    event.preventDefault();
    setError('');
    setSubmitting(true);

    try {
      const user = mode === 'login'
        ? await authApi.login({ email, password, remember })
        : await authApi.register({
            name,
            email,
            password,
            password_confirmation: passwordConfirmation,
          });
      onLogin(user);
    } catch (err) {
      setError(err.message || (mode === 'login' ? 'Bejelentkezés sikertelen.' : 'Regisztráció sikertelen.'));
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="auth-page">
      <div className="auth-card">
        <div className="auth-brand">
          <div className="logo-icon">G</div>
          <div>
            <h1 className="auth-title">Gmail Evaluator</h1>
            <p className="auth-subtitle">
              {mode === 'login' ? 'Jelentkezz be a folytatáshoz' : 'Hozz létre új fiókot'}
            </p>
          </div>
        </div>

        {error && (
          <div className="alert alert-error">
            <span>{error}</span>
          </div>
        )}

        <form onSubmit={handleSubmit} className="auth-form">
          {mode === 'register' && (
            <div className="form-group">
              <label className="form-label" htmlFor="register-name">Név</label>
              <input
                id="register-name"
                type="text"
                className="form-input"
                autoComplete="name"
                required
                value={name}
                onChange={(event) => setName(event.target.value)}
              />
            </div>
          )}

          <div className="form-group">
            <label className="form-label" htmlFor="login-email">E-mail</label>
            <input
              id="login-email"
              type="email"
              className="form-input"
              autoComplete="username"
              required
              value={email}
              onChange={(event) => setEmail(event.target.value)}
            />
          </div>

          <div className="form-group">
            <label className="form-label" htmlFor="login-password">Jelszó</label>
            <input
              id="login-password"
              type="password"
              className="form-input"
              autoComplete={mode === 'login' ? 'current-password' : 'new-password'}
              required
              value={password}
              onChange={(event) => setPassword(event.target.value)}
            />
          </div>

          {mode === 'register' && (
            <div className="form-group">
              <label className="form-label" htmlFor="register-password-confirmation">Jelszó megerősítése</label>
              <input
                id="register-password-confirmation"
                type="password"
                className="form-input"
                autoComplete="new-password"
                required
                value={passwordConfirmation}
                onChange={(event) => setPasswordConfirmation(event.target.value)}
              />
            </div>
          )}

          {mode === 'login' && (
            <label className="auth-remember">
              <input
                type="checkbox"
                checked={remember}
                onChange={(event) => setRemember(event.target.checked)}
              />
              <span>Emlékezz rám</span>
            </label>
          )}

          <button type="submit" className="btn btn-primary auth-submit" disabled={submitting}>
            {submitting
              ? (mode === 'login' ? 'Bejelentkezés...' : 'Regisztráció...')
              : (mode === 'login' ? 'Bejelentkezés' : 'Regisztráció')}
          </button>
        </form>

        <p className="auth-switch">
          {mode === 'login' ? (
            <>
              Nincs még fiókod?{' '}
              <button type="button" className="auth-switch-link" onClick={() => switchMode('register')}>
                Regisztráció
              </button>
            </>
          ) : (
            <>
              Van már fiókod?{' '}
              <button type="button" className="auth-switch-link" onClick={() => switchMode('login')}>
                Bejelentkezés
              </button>
            </>
          )}
        </p>
      </div>
    </div>
  );
}
