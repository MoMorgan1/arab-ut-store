# The supplier endpoints we actually use

Derived from the working tracker at `track.arab-ut.com` and verified against live orders on
2026-09-12. The owner's position, and the evidence agrees: **the tracker's call set is the whole
contract.** There is no need to discover more of either supplier's API. FUT Transfer publishes
Postman documentation, but it is a client-rendered page that fetches as an empty shell — if a
question is not answered here, export the collection as JSON rather than trying to read that page.

Two suppliers, fifteen-ish calls between them, and only nine matter to the store.

## FFT — `https://futtransfer.top`

JSON bodies, `apiUser` + `apiKey` in every payload. The key is stored pre-hashed; do not hash it
again.

| Endpoint | Used for | Notes |
| --- | --- | --- |
| `orderStatusAPI` | coins order status | `{orderID, apiUser, apiKey}`, plus `externalID: 1, isMotherID: 0` when the id is ours rather than FFT's. **Answers 404 with the plain-text body `notFound` for a challenge order** — it is coins-only. |
| `sbcStatusBulkAPI` | challenge status | `{sbcIDs: [...], apiUser, apiKey}`. Bulk, keyed by challenge id, one object per id. Ids are bare — strip any `SBC-` prefix. |
| `correctCredentialsAPI` | credential fix **and resume** | Not two endpoints. Resume is the same call with `continue: 1`; the tracker's `continueFftOrder()` posts here. |
| `retrySBCAPI` | retry a failed challenge solve | The tracker validates the challenge belongs to the order first and answers `403 SBC_NOT_IN_ORDER` if not. That check has no home in the store yet — see slice D1. |
| `createCustomerAPI` | rewrite the customer record | Called with `updateCustomer: '1'`. Keyed by **account email**, so its effect reaches every order on that EA account at FFT, not just the one in hand. Recorded in `docs/decisions/2026-09-12-ea-credentials-in-placement-payload.md`. |
| `availableSBCsAPI` | which challenges are solvable | Placement-time, n8n's business. |
| `maxOrderPreviewAPI` | how much FFT could move now | Placement-time only, used by the supplier decision engine in n8n. |

## UTT — `https://utautotransfer.com/api`

Form-encoded bodies, `apiKey` as a field. UTT reports raw coins where FFT reports thousands.

| Endpoint | Used for | Notes |
| --- | --- | --- |
| `getOrder` | order status | `{apiKey, idOrder}`. Returns HTTP 200 with no `order` key when the id is unknown — a 200 is not proof of an order. **Returns the receiver account joined in**, which is why the response carries `nameAccount`, `emailAccount` and `passwordAccount`; UTT's own docs describe it as "joined with its receiver account". |
| `editOrderPublic` | credential fix | A **full replace**: every field must be sent, so the current order is read first and used as the fallback for anything the customer did not change. |
| `startOrder` | resume | `{apiKey, idOrder}`. |
| `getPublicSaleStocks`, `getMaxOrderPrediction` | placement | n8n's business, not the store's. |

## What the store needs, and what it has

Tracking needs six of those: two status reads, two credential fixes, two resumes. Plus the
challenge retry.

| Call | Built? |
| --- | --- |
| FFT `orderStatusAPI` | yes, `FftClient::observe()` |
| FFT `correctCredentialsAPI` (edit) | yes, `FftClient::correctCredentials()` |
| FFT `correctCredentialsAPI` (resume) | yes, `FftClient::resume()` |
| FFT `retrySBCAPI` | yes, `FftClient::retryChallenge()` — but without the ownership check |
| UTT `getOrder` | yes, `UttClient::observe()` |
| UTT `editOrderPublic` | yes, `UttClient::correctCredentials()` |
| UTT `startOrder` | yes, `UttClient::resume()` |
| **FFT `sbcStatusBulkAPI`** | **no — challenge tracking does not work without it** |

The gap is the bulk challenge read, and it is not only a missing method. The endpoint is keyed by
challenge id rather than order id, one item can carry several ids, and the store has nowhere to put
them: the old tracker read them from the Google Sheet this work is deleting.

## Two things a reader will get wrong

**Coins units differ by supplier.** FFT's `amount` / `amountOrdered` are in thousands — 500 means
500,000 coins. UTT's `amountProcessed` / `amountTotal` are raw coins. The normalising mapper
divides on the UTT side; the translator multiplies on the way out.

**Challenge counters come in two pairs and both are shown.** `challengesDone` /
`totalChallenges` is progress through the squads of the solve being worked now; `timesSolved` /
`timesToSolve` is which solve that is out of how many the customer bought. They are two tracks on
the same screen, not a right answer and a wrong one - the existing tracker renders both. See
`CONTEXT.md`.
