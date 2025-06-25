@extends('layouts.app')

@section('title', $document->title)

@section('content')
<div class="container">
    <div class="row">
        <div class="col-md-8">
            {{-- Document Content --}}
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h1 class="card-title mb-0">{{ $document->title }}</h1>
                    <div class="dropdown">
                        <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            Actions
                        </button>
                        <ul class="dropdown-menu">
                            {{-- Show edit option only if user can edit --}}
                            @can('edit', $document)
                                <li><a class="dropdown-item" href="{{ route('documents.edit', $document) }}">
                                    <i class="fas fa-edit"></i> Edit Document
                                </a></li>
                            @endcan
                            
                            {{-- Always allow duplication if user can view --}}
                            <li><a class="dropdown-item" href="#" onclick="duplicateDocument({{ $document->id }})">
                                <i class="fas fa-copy"></i> Duplicate
                            </a></li>
                            
                            {{-- Show publish option for editors on draft documents --}}
                            @can('edit', $document)
                                @if($document->isDraft())
                                    <li><a class="dropdown-item" href="#" onclick="publishDocument({{ $document->id }})">
                                        <i class="fas fa-share"></i> Publish
                                    </a></li>
                                @endif
                            @endcan
                            
                            {{-- Show sharing options for owners --}}
                            @can('share', $document)
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#shareModal">
                                    <i class="fas fa-users"></i> Share Document
                                </a></li>
                                <li><a class="dropdown-item" href="#" onclick="showAccessList({{ $document->id }})">
                                    <i class="fas fa-list"></i> Manage Access
                                </a></li>
                            @endcan
                            
                            {{-- Show delete option only for owners --}}
                            @can('delete', $document)
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="#" onclick="deleteDocument({{ $document->id }})">
                                    <i class="fas fa-trash"></i> Delete Document
                                </a></li>
                            @endcan
                        </ul>
                    </div>
                </div>
                
                <div class="card-body">
                    {{-- Document metadata --}}
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <small class="text-muted">
                                <i class="fas fa-user"></i> By {{ $document->owner->name }}
                            </small>
                        </div>
                        <div class="col-md-6 text-end">
                            <small class="text-muted">
                                <i class="fas fa-clock"></i> {{ $document->updated_at->format('M j, Y g:i A') }}
                            </small>
                        </div>
                    </div>
                    
                    {{-- Status badge --}}
                    <div class="mb-3">
                        <span class="badge bg-{{ $document->isPublished() ? 'success' : 'warning' }}">
                            {{ ucfirst($document->status) }}
                        </span>
                        
                        {{-- Location info --}}
                        @if($document->folder)
                            <span class="badge bg-info">
                                <i class="fas fa-folder"></i> {{ $document->folder->getFullPath() }}
                            </span>
                        @endif
                        
                        @if($document->team)
                            <span class="badge bg-primary">
                                <i class="fas fa-users"></i> {{ $document->team->name }}
                            </span>
                        @endif
                    </div>
                    
                    {{-- Document excerpt --}}
                    @if($document->excerpt)
                        <div class="alert alert-light">
                            <strong>Summary:</strong> {{ $document->excerpt }}
                        </div>
                    @endif
                    
                    {{-- Document content --}}
                    <div class="document-content">
                        {!! nl2br(e($document->content)) !!}
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            {{-- Sidebar with additional info --}}
            
            {{-- Permission status for current user --}}
            <div class="card mb-3">
                <div class="card-header">
                    <h6 class="card-title mb-0">Your Permissions</h6>
                </div>
                <div class="card-body">
                    <div class="list-group list-group-flush">
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            View
                            @if($permissions['can_view'])
                                <span class="badge bg-success rounded-pill">✓</span>
                            @else
                                <span class="badge bg-danger rounded-pill">✗</span>
                            @endif
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            Edit
                            @if($permissions['can_edit'])
                                <span class="badge bg-success rounded-pill">✓</span>
                            @else
                                <span class="badge bg-danger rounded-pill">✗</span>
                            @endif
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            Own
                            @if($permissions['can_own'])
                                <span class="badge bg-success rounded-pill">✓</span>
                            @else
                                <span class="badge bg-danger rounded-pill">✗</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
            
            {{-- Document statistics (for owners) --}}
            @if($permissions['can_own'] && $accessStats)
                <div class="card mb-3">
                    <div class="card-header">
                        <h6 class="card-title mb-0">Document Statistics</h6>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-4">
                                <div class="h5 mb-0">{{ $accessStats['viewers_count'] }}</div>
                                <small class="text-muted">Viewers</small>
                            </div>
                            <div class="col-4">
                                <div class="h5 mb-0">{{ $accessStats['editors_count'] }}</div>
                                <small class="text-muted">Editors</small>
                            </div>
                            <div class="col-4">
                                <div class="h5 mb-0">{{ $accessStats['word_count'] }}</div>
                                <small class="text-muted">Words</small>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
            
            {{-- Related documents --}}
            @if($relatedDocuments->count() > 0)
                <div class="card">
                    <div class="card-header">
                        <h6 class="card-title mb-0">Related Documents</h6>
                    </div>
                    <div class="card-body">
                        <div class="list-group list-group-flush">
                            @foreach($relatedDocuments as $related)
                                <a href="{{ route('documents.show', $related) }}" class="list-group-item list-group-item-action">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">{{ $related->title }}</h6>
                                        <small>{{ $related->updated_at->diffForHumans() }}</small>
                                    </div>
                                    @if($related->excerpt)
                                        <p class="mb-1">{{ Str::limit($related->excerpt, 100) }}</p>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>

{{-- Share Modal (only shown to users who can share) --}}
@can('share', $document)
    <div class="modal fade" id="shareModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Share Document</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="{{ route('documents.share', $document) }}" method="POST">
                    @csrf
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="user_search" class="form-label">Search for user</label>
                            <input type="text" class="form-control" id="user_search" placeholder="Type name or email...">
                            <input type="hidden" name="user_id" id="selected_user_id">
                            <div id="user_results" class="list-group mt-2" style="display: none;"></div>
                        </div>
                        <div class="mb-3">
                            <label for="permission" class="form-label">Permission Level</label>
                            <select class="form-select" name="permission" id="permission">
                                <option value="viewer">Viewer - Can read the document</option>
                                <option value="editor">Editor - Can read and edit the document</option>
                                <option value="owner">Owner - Full control of the document</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Share Document</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endcan
@endsection

@push('scripts')
<script>
// Document action functions
function duplicateDocument(documentId) {
    if (confirm('Create a copy of this document?')) {
        fetch(`/documents/${documentId}/duplicate`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.redirect) {
                window.location.href = data.redirect;
            }
        })
        .catch(error => console.error('Error:', error));
    }
}

function publishDocument(documentId) {
    if (confirm('Publish this document? It will become visible to all users with access.')) {
        fetch(`/documents/${documentId}/publish`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            }
        })
        .then(() => location.reload())
        .catch(error => console.error('Error:', error));
    }
}

function deleteDocument(documentId) {
    if (confirm('Are you sure you want to delete this document? This action cannot be undone.')) {
        fetch(`/documents/${documentId}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            }
        })
        .then(() => window.location.href = '/documents')
        .catch(error => console.error('Error:', error));
    }
}

// User search for sharing
document.getElementById('user_search')?.addEventListener('input', function(e) {
    const query = e.target.value;
    const resultsDiv = document.getElementById('user_results');
    
    if (query.length < 2) {
        resultsDiv.style.display = 'none';
        return;
    }
    
    fetch(`/api/users/search?q=${encodeURIComponent(query)}`)
        .then(response => response.json())
        .then(data => {
            resultsDiv.innerHTML = '';
            data.users.forEach(user => {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'list-group-item list-group-item-action';
                item.innerHTML = `
                    <div class="d-flex w-100 justify-content-between">
                        <h6 class="mb-1">${user.name}</h6>
                        <small>${user.email}</small>
                    </div>
                `;
                item.addEventListener('click', () => {
                    document.getElementById('user_search').value = user.name;
                    document.getElementById('selected_user_id').value = user.id;
                    resultsDiv.style.display = 'none';
                });
                resultsDiv.appendChild(item);
            });
            resultsDiv.style.display = data.users.length > 0 ? 'block' : 'none';
        })
        .catch(error => console.error('Error:', error));
});

// Show access list function
function showAccessList(documentId) {
    fetch(`/documents/${documentId}/access`)
        .then(response => response.json())
        .then(data => {
            // Implementation for showing access list modal
            console.log('Access list:', data);
        })
        .catch(error => console.error('Error:', error));
}
</script>
@endpush