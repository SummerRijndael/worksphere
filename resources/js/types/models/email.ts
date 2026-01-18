export interface EmailAttachment {
    id: string;
    email_id?: number | string;
    path?: string;
    name: string;
    size: string; // e.g. "1.2 MB", formatted from backend
    type: string; // MIME type
    url?: string; // For download
    content_id?: string; // For inline image matching
}

export interface EmailLabel {
    id: string;
    name: string;
    color: string;
}

export interface EmailSender {
    name: string;
    email: string;
    avatar?: string;
}

export interface EmailRecipients {
    name?: string;
    email: string;
}

export interface Email {
    id: string;
    public_id?: string;
    message_id?: string;
    // Legacy flattened structure
    from_name: string;
    from_email: string;
    
    // recipients still usually array of objects, need to verify strict shape
    to: EmailRecipients[];
    cc: EmailRecipients[];
    bcc: EmailRecipients[];
    
    subject: string;
    preview: string;
    
    body_html: string; 
    body_plain: string;
    
    date: string; // ISO string
    
    is_read: boolean;
    is_starred: boolean;
    is_draft: boolean;
    
    has_attachments: boolean;
    attachments: EmailAttachment[];
    
    folder: string;
    labels: string[]; 
    headers?: Record<string, any>;
}

export interface EmailFolder {
    id: string;
    name: string;
    type: 'system' | 'custom';
    icon?: any; // Component type
    count?: number;
    slug?: string;
}
