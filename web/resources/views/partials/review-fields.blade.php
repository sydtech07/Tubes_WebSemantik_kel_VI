<fieldset class="mb-3">
    <legend class="form-label fs-6">Rating kamu <span class="cl-sub small">/ 10</span></legend>
    <div class="cl-rating-picker">
        @for ($score = 1; $score <= 10; $score++)
            <input type="radio" name="rating" id="rating-{{ $score }}" value="{{ $score }}" @checked((int) old('rating', $review?->rating) === $score) required>
            <label for="rating-{{ $score }}">{{ $score }}</label>
        @endfor
    </div>
    <div class="d-flex justify-content-between cl-sub small mt-2"><span>Kurang berkesan</span><span>Wajib ditonton</span></div>
</fieldset>
<label class="form-label" for="review-title">Judul ulasan <span class="cl-sub small">(opsional)</span></label>
<input class="form-control mb-3" id="review-title" name="title" maxlength="150" value="{{ old('title', $review?->title) }}" placeholder="Kesan pertamamu tentang film ini">
<label class="form-label" for="review-body">Ulasan <span class="cl-sub small">(opsional)</span></label>
<textarea class="form-control mb-3" id="review-body" name="body" rows="4" maxlength="10000" placeholder="Apa yang kamu suka? Bagikan pendapatmu…">{{ old('body', $review?->body) }}</textarea>
<div class="form-check mb-4">
    <input class="form-check-input" type="checkbox" id="contains-spoiler" name="contains_spoiler" value="1" @checked(old('contains_spoiler', $review?->contains_spoiler))>
    <label class="form-check-label small" for="contains-spoiler">Ulasan ini mengandung spoiler</label>
</div>
