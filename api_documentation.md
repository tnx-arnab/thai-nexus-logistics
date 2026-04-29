# Thai Nexus Logistics API Documentation

## Base URL
`https://app.thainexus.co.th/functions`

All endpoints use the `shipmentCrud` backend function. Pass the `action` field to select the operation.

**Authentication:** Pass your `api_token` in the request body (get it from Profile Settings).

---

## Shipment CRUD

### 1. Create Shipment
Create a new shipment request with origin/destination/dimensions. Volumetric weight is auto-calculated. Status defaults to `pending`.

**Endpoint:** `POST /shipmentCrud`
**Action:** `create`

#### Request Payload
```json
{
  "api_token": "tnx_YOUR_TOKEN",
  "action": "create",
  "data": {
    "shipper_address": {
      "name": "John Doe",
      "phone": "+66812345678",
      "address_line1": "123 Sukhumvit",
      "city": "Bangkok",
      "country": "TH"
    },
    "consignee_address": {
      "name": "Jane Smith",
      "phone": "+12025551234",
      "address_line1": "456 Main St",
      "city": "New York",
      "state": "NY",
      "postal_code": "10001",
      "country": "US"
    },
    "actual_weight_kg": 2.5,
    "length_cm": 30,
    "width_cm": 20,
    "height_cm": 15,
    "shipment_type": "parcel",
    "shipment_description": "Electronics"
  }
}
```

#### Response
```json
{
  "success": true,
  "action": "create",
  "data": {
    "id": "abc123",
    "request_number": "SR-X9K2F7",
    "status": "pending",
    "volumetric_weight_kg": 1.8,
    "submitted_date": "2026-04-24T10:00:00Z"
  }
}
```

---

### 2. Update Shipment
Update a shipment's details. Blocked for shipped/completed shipments.

**Endpoint:** `POST /shipmentCrud`
**Action:** `update`

#### Request Payload
```json
{
  "api_token": "tnx_YOUR_TOKEN",
  "action": "update",
  "request_number": "SR-X9K2F7",
  "data": {
    "actual_weight_kg": 3.0,
    "shipment_description": "Updated description"
  }
}
```

#### Response
```json
{
  "success": true,
  "action": "update",
  "data": {
    "id": "abc123",
    "request_number": "SR-X9K2F7",
    "updated_fields": ["actual_weight_kg", "shipment_description", "volumetric_weight_kg"]
  }
}
```

---

### 3. Get Shipment
Get a single shipment by ID or request number.

**Endpoint:** `POST /shipmentCrud`
**Action:** `get`

#### Request Payload
```json
{
  "api_token": "tnx_YOUR_TOKEN",
  "action": "get",
  "request_number": "SR-X9K2F7"
}
```

---

### 4. List Shipments
Paginated list of shipments for the authenticated user.

**Endpoint:** `POST /shipmentCrud`
**Action:** `list`

#### Request Payload
```json
{
  "api_token": "tnx_YOUR_TOKEN",
  "action": "list",
  "page": 1,
  "limit": 20,
  "status": "pending"
}
```

---

### 5. Delete Shipment
Soft-delete a shipment. Active/shipped shipments cannot be deleted by clients.

**Endpoint:** `POST /shipmentCrud`
**Action:** `delete`

#### Request Payload
```json
{
  "api_token": "tnx_YOUR_TOKEN",
  "action": "delete",
  "request_number": "SR-X9K2F7",
  "reason": "Created by mistake"
}
```
