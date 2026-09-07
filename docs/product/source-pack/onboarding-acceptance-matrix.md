# Appendix 1 onboarding acceptance matrix

This matrix reconciles the recovered source with current product behavior. `OVERRIDDEN BY NEWER SPEC` means the source intent is understood but the current v2.2 rule is the acceptance authority.

| Acceptance area | Current behavior | Status | Evidence or gap |
| --- | --- | --- | --- |
| Returning client prefill | Authenticated Portal/profile data can be loaded and edited through server-side client context | PARTIALLY IMPLEMENTED | Existing profile/onboarding actions; the source’s complete four-screen confirmation UI is not present |
| Telegram-verified identity | Verified Telegram authentication and identity resolution are existing boundaries | IMPLEMENTED | Telegram auth and Portal identity tests |
| Automatic referral attribution hides manual source field | Automatic attribution is stored and the manual source is requested only when needed | IMPLEMENTED | Attribution tests |
| UTM attribution hides manual source field | Automatic attribution path stores the source and suppresses redundant manual collection | IMPLEMENTED | Attribution behavior and request validation |
| Organic entry shows lead-source question | Manual attribution is available when no automatic source exists | IMPLEMENTED | Attribution feedback tests |
| Recommendation reveals recommender detail | Source choice is defined, but the current Portal flow does not expose the complete conditional recommender form | PARTIALLY IMPLEMENTED | Add only within an accepted action-specific profile flow |
| B2B answer persists through existing authority | Explicit answer uses the existing B2B profile/application action | PARTIALLY IMPLEMENTED | Candidate requirement; no duplicate tagging subsystem |
| Complaint fields | Current medical/session boundaries support complaint data, not the full source onboarding surface | PARTIALLY IMPLEMENTED | No complete Portal capture path |
| Operations conditional fields | Current encrypted medical boundaries exist, but source conditional onboarding fields are not wired as one accepted flow | PARTIALLY IMPLEMENTED | No complete Portal capture path |
| Injuries conditional fields | Current encrypted medical boundaries exist, but source conditional onboarding fields are not wired as one accepted flow | PARTIALLY IMPLEMENTED | No complete Portal capture path |
| Medicines/supplements | Existing Class C medical boundary is authoritative; the source field is not a complete Portal step | PARTIALLY IMPLEMENTED | No complete Portal capture path |
| Service list from authoritative catalog | Booking shows active services from the service catalog | IMPLEMENTED | Service/booking tests |
| Office / Home Visit / Online | Current booking supports the accepted formats | IMPLEMENTED | Booking tests |
| Home Visit conditional details | Current booking uses newer working-location, location-day, address, participant, and timezone rules | OVERRIDDEN BY NEWER SPEC | Do not replace M11/v2.2 semantics with the older Appendix 1 wording |
| Legal version evidence | Required consent records the exact current published legal-document version and evidence | IMPLEMENTED | Consent/version tests |
| Private medical attachment handling | Private UUID-backed, MIME-validated, bounded, checksum-protected Class C storage is retained | PARTIALLY IMPLEMENTED | Portal onboarding does not yet trigger the full Agent 1 pipeline; do not weaken the existing boundary |
| Final CTA by booking format | Current CTA belongs to the action-specific booking flow | OVERRIDDEN BY NEWER SPEC | The source intent is retained; current v2.2 booking behavior wins |
| JSON-driven wizard configuration | Source describes backend-driven field/copy/logic configuration | NOT IMPLEMENTED | Existing onboarding is staged/application-driven, not a complete generic JSON wizard |
| 9 systems/MSQ questionnaire and scoring | Source appendix does not define it | BLOCKED BY MISSING SOURCE | OQ-015 remains open; do not infer questions or scoring |
