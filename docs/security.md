# Security Hardening Baseline & Audit

This document outlines the security hardening baseline applied to `possible-words`.

## 1. Security Headers
The application enforces strict security headers:
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: SAMEORIGIN` (or CSP `frame-ancestors 'self'`)
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy: camera=(), microphone=(), geolocation=()`
- `Content-Security-Policy`: Configured for Inertia/Vue + Vite production builds.

## 2. Authentication & Session Security
- Cookies configured with `HttpOnly`, `Secure`, and `SameSite=Lax`.
- OAuth state validation enforced on callback endpoints to prevent CSRF and open redirects.

## 3. Least Privilege & Dependency Audits
- IAM policies scoped strictly to necessary S3 bucket resources.
- `composer audit` and `npm audit` integrated into CI workflow.
