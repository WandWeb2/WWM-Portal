# Project Name: WandWeb Standalone Site

**Agency:** Wandering Webmaster (wandweb.co)
**Architecture:** React Standalone (No Build Step)

## 1. Overview
[cite_start]This project utilizes the **Wandering Webmaster Standalone React Architecture**[cite: 86]. [cite_start]It is designed for rapid prototyping and lightweight client sites, eliminating the need for complex build tools like Webpack or Vite in favor of browser-native translation via Babel Standalone[cite: 86].

### Core Philosophy
* [cite_start]**The Single-File Mandate:** All application logic, styling, and markup reside primarily within `index.html` to ensure portability on shared hosting[cite: 88].
* **No Node.js Required:** This project does not require `npm install` or `yarn build` to run.

## 2. Tech Stack & Dependencies
We use Content Delivery Networks (CDNs) for core libraries. [cite_start]These are included in the `<head>` of the main file[cite: 89, 90]:

* [cite_start]**Tailwind CSS:** Utility-first styling[cite: 91].
* [cite_start]**React & ReactDOM (UMD):** Core UI library[cite: 92].
* [cite_start]**Babel Standalone:** Compiles JSX in the browser[cite: 93].
* [cite_start]**Google Fonts:** Typography[cite: 53].

## 3. Deployment Guidelines (Plesk)
[cite_start]Our preferred hosting environment is **Plesk**[cite: 4]. To ensure the application functions correctly in production, follow these configuration steps:

### A. SSL & Security
1.  [cite_start]**Let's Encrypt:** Go to "Websites & Domains" > "SSL/TLS Certificates" and install a free Let's Encrypt certificate[cite: 18, 19, 20].
2.  [cite_start]**Force HTTPS:** Once issued, toggle "Permanent SEO-safe 301 redirect from HTTP to HTTPS" to ON[cite: 24].

### B. Mail Configuration (Critical)
[cite_start]For the PHP `mail()` function to succeed, the local mail service must be active[cite: 6].
1.  **Create No-Reply Account:** Create an email address (e.g., `noreply@domain.com`) in Plesk. [cite_start]This exists solely to authenticate outgoing script emails[cite: 7, 8, 10].
2.  [cite_start]**Activate Service:** In "Mail Settings," ensure "Activate mail service on this domain" is CHECKED[cite: 13].

## 4. Backend & Forms (`contact.php`)
[cite_start]This project uses server-side PHP for form handling to ensure security and reliability[cite: 163].

**Configuration Rules:**
* [cite_start]**Input Sanitization:** All inputs are sanitized using `strip_tags()` and `filter_var()`[cite: 195].
* [cite_start]**The "From" Header:** Must be set to the authenticated server account (e.g., `noreply@domain.com`) to avoid "Spoofing Blocking"[cite: 174].
* [cite_start]**The "Reply-To" Header:** Set this to the user's email address so the client can reply directly[cite: 178].

## 5. Development Standards

### A. Icon System
[cite_start]**DO NOT** use external icon scripts (like `lucide-react`) as they fail in standalone environments[cite: 111]. We strictly use the **Internal SVG Pattern**:

```javascript
// Wrapper Component
const Icon = ({ children, ...props }) => (
  <svg xmlns="[http://www.w3.org/2000/svg](http://www.w3.org/2000/svg)" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" {...props}>
    {children}
  </svg>
);
