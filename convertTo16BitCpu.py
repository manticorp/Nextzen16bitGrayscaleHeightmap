import time
import numpy as np
import sys
from os import path
from PIL import Image
Image.MAX_IMAGE_PIXELS = 300000*300000

if len(sys.argv) < 2:
    raise ValueError('No file argument given')

fn = sys.argv[1]

if not path.exists(fn):
    raise ValueError('File {} does not exist'.format(fn))

usecupy = False
try:
    import cupy as cp
    usecupy = True
except ImportError or ModuleNotFoundError:
    usecupy = False

nfn = '{}-gs-norm-{}.png'.format(path.splitext(fn)[0], 'cupy' if usecupy else 'numpy')

if path.exists(nfn):
    raise ValueError('File {} already exists'.format(nfn))

factor = 2**8
rangef = 2**15
rangem = 2**16

step = 6
barwidth = 40
barchar1 = '#'
barchar2 = ' '
start = time.time()
print('Reading image - will write to {}'.format(nfn))
with Image.open(fn) as src:
    if usecupy:
        print('{} GPU devices found'.format(cp.cuda.runtime.getDeviceCount()))
        with cp.cuda.Device(0) as dev0:
            dev0.use()
            print('Compute Capability {}'.format(dev0.compute_capability))
            cparray = cp.asarray(src, dtype='uint8')
            print('Image read')
            cparray = cparray.dot(cp.array([factor,1,1/factor], dtype='float32'))
            cparray = cp.subtract(cparray, rangef, dtype='float32')
            maxv = cp.amax(cparray)
            minv = cp.amin(cparray)
            rangev = maxv - minv
            cparray = cp.subtract(cparray, minv)
            cparray = cp.multiply(cparray, rangem/(maxv - minv))
            dest = Image.fromarray(cp.asnumpy(cparray).astype('uint16'), 'I;16')
            print('Writing image - Size {}x{} - mode {}'.format(dest.width, dest.height, dest.mode))
            dest.save(nfn)
    else:
        nparray = np.asarray(src, dtype='uint8')
        print('Image read')
        nparray = nparray.dot(np.array([factor,1,1/factor], dtype='float32'))
        nparray = np.subtract(nparray, rangef, dtype='float32')
        maxv = np.amax(nparray)
        minv = np.amin(nparray)
        rangev = maxv - minv
        nparray = np.subtract(nparray, minv, dtype='float32')
        nparray = np.multiply(nparray, rangem/(maxv - minv)).astype('uint16')
        dest = Image.fromarray(nparray, 'I;16')
        print('Writing image - Size {}x{} - mode {}'.format(dest.width, dest.height, dest.mode))
        dest.save(nfn)
    print('Complete - took {:.1f}s'.format(time.time() - start))