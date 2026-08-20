# Product contract

**Lifecycle:** Implemented
**Verified:** 2026-08-20

## Purpose and users

The assistant is the website assistant and support entry point for Arab UT
customers. It currently serves guests and authenticated storefront customers.
Support agents and administrators are later users, after their workflows are
designed and approved.

## Implemented v1

- A persistent chat shell is mounted across Inertia storefront navigation.
- A conversation and its bounded history remain tied to the current guest or
  authenticated owner.
- Guests keep continuity through a session token and their conversations are
  claimed after successful login.
- Customer messages are stored and can receive an immediate bilingual canned
  demo reply when the demo flag is enabled.
- The launcher, full-screen mobile sheet, anchored desktop panel, retry state,
  older-history loading, and Arabic/English presentation are present.

The current reply is deterministic demo behavior. It is not a model response
and it does not make a claim about answer quality or availability.

## Excluded from the current product

- Autonomous order changes, cancellations, refunds, or fulfillment actions.
- Payment initiation, capture, credential access, or other financial actions.
- Model-generated answers, AI accuracy guarantees, tool calling, retrieval,
  realtime support, or an admin inbox.

## Success criteria

The foundation succeeds when conversation continuity is owner-safe, Arabic and
English directionality is clear, the browser-verified release path is reliable,
and Mohamed completes manual owner acceptance on the deployed experience.

## Later product work

**Section lifecycle:** Planned

Human support and administration, an AI turn runtime, retrieval, and approved
tools require separate discovery, design, security review, and owner approval.
