# Project Name: WandWeb Standalone Site

**Agency:** Wandering Webmaster (wandweb.co)
**Architecture:** React Standalone (No Build Step)

## 1. Overview
This project utilizes the **Wandering Webmaster Standalone React Architecture**. It is designed for rapid prototyping and lightweight client sites, eliminating the need for complex build tools like Webpack or Vite in favor of browser-native translation via Babel Standalone.

### Core Philosophy
* **The Single-File Mandate:** All application logic, styling, and markup reside primarily within `index.html` to ensure portability on shared hosting.
* **No Node.js Required:** This project does not require `npm install` or `yarn build` to run.

## 2. Tech Stack & Dependencies
We use Content Delivery Networks (CDNs) for core libraries. [cite_start]These are included in the `<head>` of the main file [cite: 58-62]:

* **Tailwind CSS:** Utility-first styling.
* **React & ReactDOM (UMD):** Core UI library.
* **Babel Standalone:** Compiles JSX in the browser.
* **Google Fonts:** Typography.

## 3. Deployment Guidelines (Plesk)
Our preferred hosting environment is **Plesk**. To ensure the application functions correctly in production, follow these configuration steps:

### A. SSL & Security
1.  [cite_start]**Let's Encrypt:** Go to "Websites & Domains" > "SSL/TLS Certificates" and install a free Let's Encrypt certificate [cite: 181-186].
2.  **Force HTTPS:** Once issued, toggle "Permanent SEO-safe 301 redirect from HTTP to HTTPS" to ON.

### B. Mail Configuration (Critical)
For the PHP `mail()` function to succeed, the local mail service must be active.
1.  **Create No-Reply Account:** Create an email address (e.g., `noreply@domain.com`) in Plesk. [cite_start]This exists solely to authenticate outgoing script emails [cite: 172-175].
2.  [cite_start]**Activate Service:** In "Mail Settings," ensure "Activate mail service on this domain" is CHECKED [cite: 176-179].

## 4. Backend & Forms (`contact.php`)
[cite_start]This project uses server-side PHP for form handling to ensure security and reliability [cite: 128-132].

**Configuration Rules:**
* **Input Sanitization:** All inputs are sanitized using `strip_tags()` and `filter_var()`.
* **The "From" Header:** Must be set to the authenticated server account (e.g., `noreply@domain.com`) to avoid "Spoofing Blocking".
* **The "Reply-To" Header:** Set this to the user's email address so the client can reply directly.

## 5. Development Standards

### A. Icon System & Accessibility
[cite_start]**DO NOT** use external icon scripts (like `lucide-react`) as they fail in standalone environments[cite: 80]. We strictly use the **Internal SVG Pattern** combined with our accessibility rules:

```javascript
// Wrapper Component
const Icon = ({ children, ...props }) => (
  <svg xmlns="[http://www.w3.org/2000/svg](http://www.w3.org/2000/svg)" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" {...props}>
    {children}
  </svg>
);

/* -----------------------------------------------------------------------------
   B. Accessibility (A11y) Standards
   -----------------------------------------------------------------------------
   Accessibility is not optional. Adhere to the following mandatory requirements:
   
   * Alt Text: All images must have descriptive `alt` tags.
   * Contrast: Ensure text color meets WCAG AA standards against backgrounds.
   * Labels: All form inputs must have a visible <label>.
   * Focus States: Do not remove default focus outlines unless replacing them 
     with a custom high-visibility alternative.
*/
