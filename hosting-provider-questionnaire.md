# NUERS hosting provider questionnaire

Send the same message to each shortlisted Philippine provider and compare answers side by side. Replace the bracketed fields before sending. Do not include database credentials, IP addresses of the current servers or any customer data.

---

**Subject:** Hosting inquiry: electronic invoicing platform, Philippines-only data location, production and DR

Hello,

We operate NUERS, a Laravel 12 (PHP) and React electronic invoicing and receipts platform for Philippine businesses. We are preparing for BIR electronic invoicing service provider (ESP) accreditation and need production hosting with all data kept in the Philippines. Please answer the questions below, in writing, so we can disclose the arrangement accurately to the regulator.

Company: [legal name] · Contact: [name, role, mobile, email] · Target start: [date]

## A. What we need to run (Stage 1, starting point)

- 1 web/application server, Linux (Ubuntu LTS or Debian), full root/SSH, about 4 vCPU / 8 GB RAM / 100 GB SSD. Runs nginx, PHP-FPM 8.3+, Redis, supervisor, cron every minute, Node.js 22 and headless Chromium (server-side PDF generation).
- 1 separate database server or managed MySQL 8 / MariaDB 10.6+ (InnoDB), about 4 vCPU / 16 GB RAM / 200 GB SSD, private network only, no public IP.
- Encrypted backups (daily full and point-in-time recovery if available), kept in a second Philippine site, with restore tests.
- Outbound access: HTTPS 443 to arbitrary public hosts (merchant webhooks), SMTP submission 587/465 to a mail provider, DNS and NTP.
- We will put Cloudflare (or a similar CDN/WAF) in front and restrict origin access to it.

## B. Later stages (please tell us what you can offer and when)

- Second web server behind a load balancer; shared object storage (S3-compatible) with versioning and object lock; shared Redis; database replica with automated failover and an independent witness/quorum.
- Disaster-recovery site in a different Philippine location, with tested failover.
- Capacity target we must eventually demonstrate: about 1,000,000 transactions per hour sustained (about 278 per second) with bursts to 2,000 per second, plus the loss of a node without losing acknowledged writes.

## C. Questions

**Location and control**
1. In which exact data center(s) and city would our servers, databases, backups and DR copies reside? Is every component, including backups, support tooling and logs, kept in the Philippines?
2. Are any subcontractors, foreign affiliates or foreign-based staff able to access our systems or data? Please list them and the access type.
3. Who holds administrative access to the hypervisor/host layer, and how is it logged and approved?
4. Can you provide a signed statement of data location and the above disclosures for our accreditation file?
5. Which independent certifications and attestations do you hold (for example ISO 27001, ISO 27017/27018, SOC 2, PCI DSS, Uptime Institute or equivalent tier rating)? Can we see the reports under NDA?

**Platform**
6. Can we install our own software (Chromium, Node.js, Redis) and run long-lived processes and per-minute cron without restrictions?
7. Do you offer managed MySQL/MariaDB (versions, backups, point-in-time recovery, read replicas, automatic failover)? If not, will you support a self-managed pair with replication?
8. Do you offer S3-compatible object storage with versioning and object lock/immutability? Block storage snapshots?
9. Do you offer a load balancer, private networking/VLAN, firewall rules and static IPv4/IPv6?
10. What DDoS protection and WAF are included, at what capacity, and can the origin be locked to a CDN's address ranges?
11. Is outbound SMTP allowed, or do you require a relay? Any restrictions on outbound HTTPS to customer webhook URLs?
12. Do you provide monitoring, alerting and log retention, and can logs be exported to our own storage? Is time synchronized (NTP) across all systems?

**Reliability and resilience**
13. What is the contractual availability SLA for compute, storage, network and managed database? What are the service credits?
14. What are your achievable RPO and RTO for backups and for failover to a second Philippine site? Have you run such failover tests for customers?
15. How do you handle node failure, planned maintenance and power/network redundancy at the data center?
16. Can you support a bursting load of 2,000 requests per second, and a sustained 278 per second, and allow us to run a load test in a staging environment?

**Security and incidents**
17. How quickly will you notify us of a security incident or outage? Please give the process, channels and response times. We must report critical incidents to the BIR within 24 hours.
18. Do you provide encryption at rest and in transit, key management, and customer-controlled encryption keys?
19. What is your support model (hours, language, escalation, named technical contact) and the response time for production-down issues?

**Commercial**
20. Please quote monthly prices for Stage 1 (section A) and for Stage 2 (section B), including backups, bandwidth, load balancer, IPs, managed services and support. Please separate one-time setup fees.
21. What are the contract term, minimum commitment, and exit terms? Can we export all data (including full images/snapshots) on termination, and for how long do you retain our data after termination?
22. Have you hosted BIR-related, e-invoicing, fintech or other regulated Philippine workloads? Can you provide references?

## D. Please send

- Written answers to the above.
- Quotation(s) and the SLA document.
- Data center details and certifications.
- A suggested date for a technical call.

Thank you,
[name] · [company] · [phone]

---

## Contact routes (verified from provider pages on 2026-10-26)

| Provider | Contact | Notes |
|---|---|---|
| Alibaba Cloud | https://www.alibabacloud.com/contact-sales (sales chat); contact.us@alibabacloud.com | Philippines-targeted page; confirm the Manila region and the products you need (RDS, OSS, SLB) with sales |
| STT GDC Philippines | inquiry@sttelemediagdc.ph; form at https://www.sttgdc.com/ph-en/contact | Makati data center (2275-2281 Chino Roces Ave). Lists colocation, connectivity and support services, not cloud VMs: likely a place to put your own servers |
| Zenlayer | form at https://www.zenlayer.com/global/datacenters/mnl1#contact (no email listed) | Its Manila site lists only networking products, no virtual machines or bare metal. Not a fit for hosting the app itself |
| CloudLake | info@cloudlake.ph and https://cloudlake.ph/contact | **Verify before trusting:** the displayed phone number (+63 917 123 4567) looks like a placeholder and the footer email link points to a different address. Offers VMs and DR; managed database, object storage and load balancer not listed |
| VITRO (ePLDT) | Not found: the website would not load for me | Look up the sales contact through PLDT enterprise or the PeeringDB listing for the VITRO facility |
| Herza Cloud | https://herza.cloud/vps-phillipines/ | Budget VPS at STT Makati. Only consider for staging |
