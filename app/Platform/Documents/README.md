# Platform Documents

Documents is a cross-domain Platform capability for document identity, binary-file references, templates, versions, signatures, relations and permissions.

It deliberately contains no Sales, Finance, HR, Construction or Procurement semantics. Business Domains reference the capability through contracts; storage, e-signature providers and external document systems belong to Infrastructure adapters.

`Platform\Knowledge\Model\Document` remains the Knowledge/RAG projection. `Platform\Documents\Model\Document` is the generic business-document capability. They are not interchangeable sources of truth.
